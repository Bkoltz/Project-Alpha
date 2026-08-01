<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/invoice_content_links.php';

use PHPUnit\Framework\TestCase;

final class InvoiceContentLinksTest extends TestCase
{
    private PDO $pdo;
    private array $ids = [];
    private array $originalConfig = [];

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
        } catch (Throwable $error) {
            $this->markTestSkipped('MySQL backend unavailable: ' . $error->getMessage());
        }

        foreach (['invoice_content_links_enabled', 'project_specific_links_enabled'] as $key) {
            $stmt = $this->pdo->prepare('SELECT config_value FROM app_config WHERE organization_id = 0 AND config_key = ? LIMIT 1');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            $this->originalConfig[$key] = $value === false ? null : (string)$value;
        }

        $this->setConfig('invoice_content_links_enabled', '1');
        $this->setConfig('project_specific_links_enabled', '0');
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        foreach (array_reverse($this->ids['links'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM entity_links WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['project_invoices'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM project_invoices WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['invoices'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['projects'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['clients'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['departments'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM organization_departments WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['organizations'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM organizations WHERE id = ?')->execute([$id]);
        }

        foreach ($this->originalConfig as $key => $value) {
            if ($value === null) {
                $this->pdo->prepare('DELETE FROM app_config WHERE organization_id = 0 AND config_key = ?')->execute([$key]);
            } else {
                $this->setConfig($key, $value);
            }
        }
    }

    public function testProjectInvoiceUsesDepartmentAndAllowedInheritedLinksOnly(): void
    {
        foreach ([
            ['organization_departments', 'id'],
            ['projects', 'department_id'],
            ['project_clients', 'can_view_invoice_links'],
            ['entity_links', 'include_on_invoices'],
            ['entity_links', 'selected_department_ids'],
        ] as [$table, $column]) {
            $this->assertTrue(invoice_content_links_table_has_column($this->pdo, $table, $column), "$table.$column is required");
        }

        $suffix = bin2hex(random_bytes(5));
        $orgId = $this->insertOrganization('WHS ' . $suffix);
        $deptId = $this->insertDepartment($orgId, 'Football');
        $steveId = $this->insertClient('Steve ' . $suffix, $orgId, 'steve-' . $suffix . '@example.invalid');
        $jeffId = $this->insertClient('Jeff ' . $suffix, $orgId, 'jeff-' . $suffix . '@example.invalid');
        $projectId = $this->insertProject($orgId, $deptId, $steveId, '2026 WHS Football ' . $suffix);
        $this->insertProjectClient($projectId, $steveId, 1, 1, 1);
        $this->insertProjectClient($projectId, $jeffId, 0, 0, 0);
        $projectInvoiceId = $this->insertProjectInvoice($projectId, $orgId, $steveId);

        $departmentLink = $this->insertLink('department', $deptId, 'Football folder', 'https://example.invalid/football-' . $suffix, 'manual_dropbox', 'entity_only');
        $orgInheritedLink = $this->insertLink('organization', $orgId, 'WHS shared', 'https://example.invalid/whs-shared-' . $suffix, 'manual_dropbox', 'all_departments');
        $this->insertLink('organization', $orgId, 'WHS private', 'https://example.invalid/whs-private-' . $suffix, 'manual_dropbox', 'entity_only');
        $clientLink = $this->insertLink('client', $steveId, 'Steve WebODM', 'https://example.invalid/webodm-' . $suffix, 'manual_webodm_map', 'entity_only');
        $this->insertLink('client', $jeffId, 'Jeff private', 'https://example.invalid/jeff-' . $suffix, 'manual_dropbox', 'entity_only');
        $this->insertLink('project', $projectId, 'Project override disabled', 'https://example.invalid/project-' . $suffix, 'manual_external', 'entity_only');

        $links = invoice_content_links_for_project_invoice($this->pdo, $projectInvoiceId);
        $urls = array_column($links, 'url');

        $this->assertContains('https://example.invalid/football-' . $suffix, $urls, 'Department folder should be included.');
        $this->assertContains('https://example.invalid/whs-shared-' . $suffix, $urls, 'Inherited org folder should be included.');
        $this->assertContains('https://example.invalid/webodm-' . $suffix, $urls, 'Allowed recipient manual WebODM link should be included.');
        $this->assertNotContains('https://example.invalid/whs-private-' . $suffix, $urls, 'Org private folder must not leak into department invoices.');
        $this->assertNotContains('https://example.invalid/jeff-' . $suffix, $urls, 'Non-link-viewer client link must not be included.');
        $this->assertNotContains('https://example.invalid/project-' . $suffix, $urls, 'Project-specific links are disabled by default.');
        $this->assertSame($departmentLink, (int)$links[0]['id']);
        $this->assertContains($orgInheritedLink, array_map(static fn($row) => (int)$row['id'], $links));
        $this->assertContains($clientLink, array_map(static fn($row) => (int)$row['id'], $links));
    }

    public function testProjectClientSyncRemovesContactsWithoutDeletingClientOrKeepingLinkAccess(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/project_invoice_billing.php';

        foreach ([
            ['project_clients', 'can_view_invoice_links'],
            ['project_clients', 'send_project_invoices'],
        ] as [$table, $column]) {
            $this->assertTrue(invoice_content_links_table_has_column($this->pdo, $table, $column), "$table.$column is required");
        }

        $suffix = bin2hex(random_bytes(5));
        $orgId = $this->insertOrganization('Sync Org ' . $suffix);
        $steveId = $this->insertClient('Sync Steve ' . $suffix, $orgId, 'sync-steve-' . $suffix . '@example.invalid');
        $jeffId = $this->insertClient('Sync Jeff ' . $suffix, $orgId, 'sync-jeff-' . $suffix . '@example.invalid');
        $projectId = $this->insertProject($orgId, 0, $steveId, 'Sync Project ' . $suffix);

        project_invoice_sync_clients($this->pdo, $projectId, $steveId, [$steveId, $jeffId], [$steveId, $jeffId], [$steveId, $jeffId]);
        project_invoice_sync_clients($this->pdo, $projectId, $steveId, [$steveId], [$steveId, $jeffId], [$steveId, $jeffId]);

        $rows = $this->pdo->prepare('SELECT client_id, send_project_invoices, can_view_invoice_links FROM project_clients WHERE project_id = ? ORDER BY client_id');
        $rows->execute([$projectId]);
        $projectClients = $rows->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $projectClients);
        $this->assertSame($steveId, (int)$projectClients[0]['client_id']);
        $this->assertSame(1, (int)$projectClients[0]['send_project_invoices']);
        $this->assertSame(1, (int)$projectClients[0]['can_view_invoice_links']);

        $clientExists = $this->pdo->prepare('SELECT COUNT(*) FROM clients WHERE id = ?');
        $clientExists->execute([$jeffId]);
        $this->assertSame(1, (int)$clientExists->fetchColumn(), 'Removing Jeff from a project must not delete the client.');
    }

    public function testSelectedDepartmentInheritanceOnlyMatchesSelectedDepartment(): void
    {
        foreach ([
            ['organization_departments', 'id'],
            ['projects', 'department_id'],
            ['entity_links', 'selected_department_ids'],
        ] as [$table, $column]) {
            $this->assertTrue(invoice_content_links_table_has_column($this->pdo, $table, $column), "$table.$column is required");
        }

        $suffix = bin2hex(random_bytes(5));
        $orgId = $this->insertOrganization('Selected Dept Org ' . $suffix);
        $footballDeptId = $this->insertDepartment($orgId, 'Football');
        $highSchoolDeptId = $this->insertDepartment($orgId, 'High School');
        $steveId = $this->insertClient('Selected Steve ' . $suffix, $orgId, 'selected-steve-' . $suffix . '@example.invalid');
        $craigId = $this->insertClient('Selected Craig ' . $suffix, $orgId, 'selected-craig-' . $suffix . '@example.invalid');

        $footballProjectId = $this->insertProject($orgId, $footballDeptId, $steveId, 'Selected Football ' . $suffix);
        $highSchoolProjectId = $this->insertProject($orgId, $highSchoolDeptId, $craigId, 'Selected High School ' . $suffix);
        $this->insertProjectClient($footballProjectId, $steveId, 1, 1, 1);
        $this->insertProjectClient($highSchoolProjectId, $craigId, 1, 1, 1);

        $footballInvoiceId = $this->insertProjectInvoice($footballProjectId, $orgId, $steveId);
        $highSchoolInvoiceId = $this->insertProjectInvoice($highSchoolProjectId, $orgId, $craigId);

        $this->insertLink(
            'organization',
            $orgId,
            'Football selected folder',
            'https://example.invalid/selected-football-' . $suffix,
            'manual_dropbox',
            'selected_departments',
            [$footballDeptId]
        );
        $this->insertLink(
            'department',
            $highSchoolDeptId,
            'High School department folder',
            'https://example.invalid/selected-highschool-' . $suffix,
            'manual_dropbox',
            'entity_only'
        );

        $footballUrls = array_column(invoice_content_links_for_project_invoice($this->pdo, $footballInvoiceId), 'url');
        $highSchoolUrls = array_column(invoice_content_links_for_project_invoice($this->pdo, $highSchoolInvoiceId), 'url');

        $this->assertContains('https://example.invalid/selected-football-' . $suffix, $footballUrls);
        $this->assertNotContains('https://example.invalid/selected-highschool-' . $suffix, $footballUrls);
        $this->assertNotContains('https://example.invalid/selected-football-' . $suffix, $highSchoolUrls);
        $this->assertContains('https://example.invalid/selected-highschool-' . $suffix, $highSchoolUrls);
    }

    public function testInvoiceContentLinksGlobalGateReturnsNoLinksWhenDisabled(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $orgId = $this->insertOrganization('Disabled Links Org ' . $suffix);
        $deptId = $this->insertDepartment($orgId, 'Disabled Dept');
        $clientId = $this->insertClient('Disabled Client ' . $suffix, $orgId, 'disabled-client-' . $suffix . '@example.invalid');
        $projectId = $this->insertProject($orgId, $deptId, $clientId, 'Disabled Project ' . $suffix);
        $this->insertProjectClient($projectId, $clientId, 1, 1, 1);
        $projectInvoiceId = $this->insertProjectInvoice($projectId, $orgId, $clientId);
        $this->insertLink('department', $deptId, 'Disabled folder', 'https://example.invalid/disabled-' . $suffix, 'manual_dropbox', 'entity_only');

        $this->setConfig('invoice_content_links_enabled', '0');

        $this->assertSame([], invoice_content_links_for_project_invoice($this->pdo, $projectInvoiceId));
    }

    public function testStandaloneClientInvoiceUsesClientContentLinks(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $clientId = $this->insertClient('Standalone Client ' . $suffix, null, 'standalone-' . $suffix . '@example.invalid');
        $invoiceId = $this->insertInvoice($clientId);
        $this->insertLink('client', $clientId, 'Standalone folder', 'https://example.invalid/standalone-' . $suffix, 'manual_dropbox', 'entity_only');

        $urls = array_column(invoice_content_links_for_invoice($this->pdo, $invoiceId), 'url');

        $this->assertContains('https://example.invalid/standalone-' . $suffix, $urls, 'Standalone clients without an organization should still pull client links.');
    }

    public function testMissingContentLinksWarningIsEnforcedBeforeEmailSend(): void
    {
        $emailSend = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/email_send.php');
        $projectEmail = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/project/project_invoice_email.php');
        $finalize = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/invoice/invoice_finalize.php');
        $lifecycle = file_get_contents(dirname(__DIR__, 2) . '/src/utils/invoice_lifecycle.php');
        $delivery = file_get_contents(dirname(__DIR__, 2) . '/src/utils/invoice_notifications.php');
        $invoiceDetails = file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/invoice/invoice-details.php');
        $projectInvoiceDetails = file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/project/project-invoice-details.php');
        $contentLinks = file_get_contents(dirname(__DIR__, 2) . '/src/utils/invoice_content_links.php');
        $invoiceLinks = file_get_contents(dirname(__DIR__, 2) . '/src/utils/invoice_links.php');

        self::assertStringContainsString('invoice_missing_content_links_behavior', (string)$contentLinks);
        self::assertStringContainsString('link_resolver_invoice_auto_attach_enabled', (string)$invoiceLinks);
        self::assertStringContainsString('pa_invoice_links_run_just_in_time_refresh', (string)$invoiceLinks);
        self::assertStringContainsString('refreshLinks(\'client\'', (string)$invoiceLinks);
        self::assertStringContainsString('invoice_should_prompt_for_missing_content_links', (string)$emailSend);
        self::assertStringContainsString('confirm_missing_content_links', (string)$emailSend);
        self::assertStringContainsString('invoice_should_prompt_for_missing_content_links', (string)$projectEmail);
        self::assertStringContainsString('content_link_warning=1&email_panel=1', (string)$projectEmail);
        self::assertStringContainsString('invoice_should_prompt_for_missing_content_links', (string)$finalize);
        self::assertStringContainsString('invoice_missing_content_links_behavior($appConfig) === \'block\'', (string)$lifecycle . (string)$delivery);
        self::assertStringContainsString('invoice_content_links_html(invoice_content_links_for_invoice', (string)$lifecycle . (string)$delivery);
        self::assertStringContainsString('Finalize & Send Anyway', (string)$invoiceDetails);
        self::assertStringContainsString('No invoice content links found.', (string)$projectInvoiceDetails);
    }

    private function setConfig(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO app_config (organization_id, config_key, config_value)
            VALUES (0, ?, ?)
            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
        ');
        $stmt->execute([$key, $value]);
    }

    private function insertOrganization(string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO organizations (name) VALUES (?)');
        $stmt->execute([$name]);
        return $this->remember('organizations', (int)$this->pdo->lastInsertId());
    }

    private function insertDepartment(int $organizationId, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO organization_departments (organization_id, name, folder_name) VALUES (?, ?, ?)');
        $stmt->execute([$organizationId, $name, $name]);
        return $this->remember('departments', (int)$this->pdo->lastInsertId());
    }

    private function insertClient(string $name, ?int $organizationId, string $email): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO clients (name, email, organization_id) VALUES (?, ?, ?)');
        $stmt->execute([$name, $email, $organizationId]);
        return $this->remember('clients', (int)$this->pdo->lastInsertId());
    }

    private function insertInvoice(int $clientId): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO invoices
                (client_id, status, billing_mode, subtotal, total, balance_due, collection_mode, finalized_at)
            VALUES (?, "sent", "fixed", 100, 100, 100, "direct", NOW())
        ');
        $stmt->execute([$clientId]);
        return $this->remember('invoices', (int)$this->pdo->lastInsertId());
    }

    private function insertProject(int $organizationId, int $departmentId, int $clientId, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO projects (name, organization_id, department_id, client_id, invoice_billing_period) VALUES (?, ?, ?, ?, "monthly")');
        $stmt->execute([$name, $organizationId, $departmentId > 0 ? $departmentId : null, $clientId]);
        return $this->remember('projects', (int)$this->pdo->lastInsertId());
    }

    private function insertProjectClient(int $projectId, int $clientId, int $primary, int $send, int $links): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO project_clients (project_id, client_id, is_primary_billing, send_project_invoices, can_view_invoice_links) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$projectId, $clientId, $primary, $send, $links]);
    }

    private function insertProjectInvoice(int $projectId, int $organizationId, int $clientId): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO project_invoices
                (project_id, organization_id, primary_client_id, status, billing_period_start, billing_period_end, total, balance_due)
            VALUES (?, ?, ?, "unpaid", CURDATE(), CURDATE(), 100, 100)
        ');
        $stmt->execute([$projectId, $organizationId, $clientId]);
        return $this->remember('project_invoices', (int)$this->pdo->lastInsertId());
    }

    private function insertLink(
        string $type,
        int $id,
        string $title,
        string $url,
        string $linkType,
        string $visibilityScope,
        array $selectedDepartmentIds = []
    ): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO entity_links
                (entity_type, entity_id, title, url, link_type, link_source, include_on_invoices, visibility_scope, selected_department_ids, is_expired)
            VALUES (?, ?, ?, ?, ?, "manual", 1, ?, ?, 0)
        ');
        $stmt->execute([
            $type,
            $id,
            $title,
            $url,
            $linkType,
            $visibilityScope,
            $selectedDepartmentIds ? json_encode(array_values(array_map('intval', $selectedDepartmentIds))) : null,
        ]);
        return $this->remember('links', (int)$this->pdo->lastInsertId());
    }

    private function remember(string $bucket, int $id): int
    {
        $this->ids[$bucket][] = $id;
        return $id;
    }
}
