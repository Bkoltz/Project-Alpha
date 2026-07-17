<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/services/LinkResolverService.php';

use PHPUnit\Framework\TestCase;

final class LinkResolverServiceTest extends TestCase
{
    private PDO $pdo;
    private array $ids = [];
    private array $originalConfig = [];
    private array $originalProviderRows = [];

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
        } catch (Throwable $error) {
            $this->markTestSkipped('MySQL backend unavailable: ' . $error->getMessage());
        }

        foreach (['link_resolver_enabled', 'default_link_expiration_days', 'link_resolver_scan_mode', 'org_level_links_only'] as $key) {
            $stmt = $this->pdo->prepare('SELECT config_value FROM app_config WHERE organization_id = 0 AND config_key = ? LIMIT 1');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            $this->originalConfig[$key] = $value === false ? null : (string)$value;
        }
        $providerStmt = $this->pdo->prepare('SELECT * FROM link_resolver_config WHERE provider = ?');
        $providerStmt->execute(['dropbox']);
        $this->originalProviderRows = $providerStmt->fetchAll(PDO::FETCH_ASSOC);
        $this->pdo->prepare('DELETE FROM link_resolver_config WHERE provider = ?')->execute(['dropbox']);

        $this->setConfig('link_resolver_enabled', '1');
        $this->setConfig('default_link_expiration_days', '30');
        $this->setConfig('link_resolver_scan_mode', 'quick');
        $this->setConfig('org_level_links_only', '0');
        $this->pdo->prepare('
            INSERT INTO link_resolver_config (provider, is_enabled, credentials, default_expiration_days)
            VALUES ("dropbox", 1, "{}", 30)
        ')->execute();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        foreach (array_reverse($this->ids['links'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM entity_links WHERE id = ?')->execute([$id]);
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

        $this->pdo->prepare('DELETE FROM link_resolver_config WHERE provider = ?')->execute(['dropbox']);
        foreach ($this->originalProviderRows as $row) {
            $columns = array_keys($row);
            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            $this->pdo->prepare('INSERT INTO link_resolver_config (' . implode(',', $columns) . ') VALUES (' . $placeholders . ')')
                ->execute(array_values($row));
        }
    }

    public function testDepartmentResolverAttachesOnlyExactFolderUnderParentOrg(): void
    {
        $orgName = 'WHS ' . bin2hex(random_bytes(3));
        $orgId = $this->insertOrganization($orgName);
        $deptId = $this->insertDepartment($orgId, 'Football', 'Football', 'auto_attach');

        $provider = new FakeResolverProvider([
            'Football' => [
                ['folder_id' => '/' . $orgName . '/Football', 'name' => 'Football', 'path' => '/' . $orgName . '/Football'],
                ['folder_id' => '/Other School/Football', 'name' => 'Football', 'path' => '/Other School/Football'],
            ],
        ]);
        $service = $this->service($provider);

        $result = $service->autoGenerateForDepartment($deptId);

        self::assertTrue($result['success'], json_encode($result));
        self::assertSame(['dropbox'], $result['generated']);

        $link = $this->fetchOneLink('department', $deptId);
        $this->remember('links', (int)$link['id']);
        self::assertSame('auto_dropbox', $link['link_type']);
        self::assertSame('resolver', $link['link_source']);
        self::assertSame(1, (int)$link['include_on_invoices']);
        self::assertSame('https://example.invalid/' . $orgName . '/Football', $link['url']);
    }

    public function testReviewModeFindsCandidateButDoesNotAttach(): void
    {
        $orgName = 'WHS Review ' . bin2hex(random_bytes(3));
        $orgId = $this->insertOrganization($orgName);
        $deptId = $this->insertDepartment($orgId, 'High School', 'HighSchool', 'review');
        $service = $this->service(new FakeResolverProvider([
            'HighSchool' => [
                ['folder_id' => '/' . $orgName . '/HighSchool', 'name' => 'HighSchool', 'path' => '/' . $orgName . '/HighSchool'],
            ],
        ]));

        $result = $service->autoGenerateForDepartment($deptId);

        self::assertFalse($result['success']);
        self::assertArrayHasKey('dropbox', $result['review_required']);
        self::assertSame(0, $this->countLinks('department', $deptId));
    }

    public function testAmbiguousDepartmentMatchesRequireReviewInsteadOfAutoAttach(): void
    {
        $orgName = 'WHS Ambiguous ' . bin2hex(random_bytes(3));
        $orgId = $this->insertOrganization($orgName);
        $deptId = $this->insertDepartment($orgId, 'Football', 'Football', 'auto_attach');
        $service = $this->service(new FakeResolverProvider([
            'Football' => [
                ['folder_id' => '/' . $orgName . '/Football', 'name' => 'Football', 'path' => '/' . $orgName . '/Football'],
                ['folder_id' => '/' . $orgName . '/Football-alt', 'name' => 'Football', 'path' => '/' . $orgName . '/Football-alt'],
            ],
        ]));

        $result = $service->autoGenerateForDepartment($deptId);

        self::assertFalse($result['success']);
        self::assertArrayHasKey('dropbox', $result['review_required']);
        self::assertSame(0, $this->countLinks('department', $deptId));
    }

    public function testSimpleOrganizationWithoutDepartmentsCanAutoAttachOwnFolder(): void
    {
        $orgName = 'Tree-B-Gone ' . bin2hex(random_bytes(3));
        $orgId = $this->insertOrganization($orgName);
        $service = $this->service(new FakeResolverProvider([
            $orgName => [
                ['folder_id' => '/' . $orgName, 'name' => $orgName, 'path' => '/' . $orgName],
            ],
        ]));

        $result = $service->autoGenerateForOrganization($orgId);

        self::assertTrue($result['success'], json_encode($result));
        $link = $this->fetchOneLink('organization', $orgId);
        $this->remember('links', (int)$link['id']);
        self::assertSame('https://example.invalid/' . $orgName, $link['url']);
        self::assertSame('entity_only', $link['visibility_scope']);
    }

    public function testDepartmentedOrganizationInDepartmentOnlyModeRemovesResolverOrgLinks(): void
    {
        $orgName = 'Dept Only ' . bin2hex(random_bytes(3));
        $orgId = $this->insertOrganization($orgName);
        $this->pdo->prepare('UPDATE organizations SET link_strategy = "department_links_only" WHERE id = ?')->execute([$orgId]);
        $deptId = $this->insertDepartment($orgId, 'Football', 'Football', 'auto_attach');
        $staleOrgLinkId = $this->insertResolverLink('organization', $orgId, 'https://example.invalid/stale-org');

        $service = $this->service(new FakeResolverProvider([
            $orgName => [
                ['folder_id' => '/' . $orgName, 'name' => $orgName, 'path' => '/' . $orgName],
            ],
            'Football' => [
                ['folder_id' => '/' . $orgName . '/Football', 'name' => 'Football', 'path' => '/' . $orgName . '/Football'],
            ],
        ]));

        $result = $service->autoGenerateForOrganization($orgId);

        self::assertTrue($result['success'], json_encode($result));
        self::assertSame(0, $this->countLinks('organization', $orgId), 'Resolver-created org links should be removed in department-only mode.');

        $link = $this->fetchOneLink('department', $deptId);
        $this->remember('links', (int)$link['id']);
        self::assertNotSame($staleOrgLinkId, (int)$link['id']);
        self::assertSame('https://example.invalid/' . $orgName . '/Football', $link['url']);
    }

    public function testSharedFolderStrategyCreatesInheritedOrganizationLink(): void
    {
        $orgName = 'Shared Strategy ' . bin2hex(random_bytes(3));
        $orgId = $this->insertOrganization($orgName);
        $this->pdo->prepare('UPDATE organizations SET link_strategy = "shared_folder" WHERE id = ?')->execute([$orgId]);
        $this->insertDepartment($orgId, 'Football', 'Football', 'auto_attach');
        $service = $this->service(new FakeResolverProvider([
            '_shared' => [
                ['folder_id' => '/' . $orgName . '/_shared', 'name' => '_shared', 'path' => '/' . $orgName . '/_shared'],
            ],
        ]));

        $result = $service->autoGenerateForOrganization($orgId);

        self::assertTrue($result['success'], json_encode($result));
        $link = $this->fetchOneLink('organization', $orgId);
        $this->remember('links', (int)$link['id']);
        self::assertSame('Shared organization files', $link['title']);
        self::assertSame('https://example.invalid/' . $orgName . '/_shared', $link['url']);
        self::assertSame('all_departments', $link['visibility_scope']);
    }

    public function testOrganizationClientAutoGenerationIsDeniedForSafety(): void
    {
        $orgId = $this->insertOrganization('WHS Client Safety ' . bin2hex(random_bytes(3)));
        $clientId = $this->insertClient($orgId, 'Steve Coach');
        $service = $this->service(new FakeResolverProvider([
            'Steve Coach' => [
                ['folder_id' => '/Steve Coach', 'name' => 'Steve Coach', 'path' => '/Steve Coach'],
            ],
        ]));

        $result = $service->autoGenerateForClient($clientId);

        self::assertFalse($result['success']);
        self::assertStringContainsString('organization or department links', $result['message']);
        self::assertSame(0, $this->countLinks('client', $clientId));
    }

    public function testStandaloneClientAutoGenerationAttachesClientFolder(): void
    {
        $clientName = 'Standalone Client ' . bin2hex(random_bytes(3));
        $clientId = $this->insertClient(null, $clientName);
        $service = $this->service(new FakeResolverProvider([
            $clientName => [
                ['folder_id' => '/' . $clientName, 'name' => $clientName, 'path' => '/' . $clientName],
            ],
        ]));

        $result = $service->autoGenerateForClient($clientId);

        self::assertTrue($result['success'], json_encode($result));
        $link = $this->fetchOneLink('client', $clientId);
        $this->remember('links', (int)$link['id']);
        self::assertSame('auto_dropbox', $link['link_type']);
        self::assertSame('resolver', $link['link_source']);
        self::assertSame(1, (int)$link['include_on_invoices']);
        self::assertSame('https://example.invalid/' . $clientName, $link['url']);
        self::assertNull($link['expiration_date']);
    }

    public function testFullScanMarksResolverLinkUnavailableWhenFolderWasRemoved(): void
    {
        $clientName = 'Removed Folder ' . bin2hex(random_bytes(3));
        $clientId = $this->insertClient(null, $clientName);
        $linkId = $this->insertResolverLink('client', $clientId, 'https://example.invalid/removed');
        $this->pdo->prepare('UPDATE entity_links SET expiration_date = DATE_ADD(CURDATE(), INTERVAL 365 DAY) WHERE id = ?')->execute([$linkId]);
        $this->setConfig('link_resolver_scan_mode', 'full');

        $result = $this->service(new FakeResolverProvider([]))->autoGenerateForClient($clientId);

        self::assertFalse($result['success']);
        self::assertStringContainsString('marked unavailable', $result['message']);
        $link = $this->fetchOneLink('client', $clientId);
        self::assertSame(1, (int)$link['is_expired']);
        self::assertNull($link['expiration_date']);
    }

    private function service(FakeResolverProvider $provider): LinkResolverService
    {
        return new LinkResolverService($this->pdo, static fn(string $name, array $credentials): object => $provider);
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

    private function insertDepartment(int $orgId, string $name, string $folderName, string $mode): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO organization_departments (organization_id, name, folder_name, resolver_mode)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$orgId, $name, $folderName, $mode]);
        return $this->remember('departments', (int)$this->pdo->lastInsertId());
    }

    private function insertClient(?int $orgId, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO clients (name, email, organization_id) VALUES (?, ?, ?)');
        $stmt->execute([$name, strtolower(str_replace(' ', '-', $name)) . '-' . bin2hex(random_bytes(3)) . '@example.invalid', $orgId]);
        return $this->remember('clients', (int)$this->pdo->lastInsertId());
    }

    private function fetchOneLink(string $entityType, int $entityId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM entity_links WHERE entity_type = ? AND entity_id = ? LIMIT 1');
        $stmt->execute([$entityType, $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'Expected resolver link to exist.');
        return $row;
    }

    private function countLinks(string $entityType, int $entityId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM entity_links WHERE entity_type = ? AND entity_id = ?');
        $stmt->execute([$entityType, $entityId]);
        return (int)$stmt->fetchColumn();
    }

    private function insertResolverLink(string $entityType, int $entityId, string $url): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO entity_links
                (entity_type, entity_id, title, url, link_type, link_source, include_on_invoices, visibility_scope, is_expired)
            VALUES (?, ?, "Old resolver folder", ?, "auto_dropbox", "resolver", 1, "entity_only", 0)
        ');
        $stmt->execute([$entityType, $entityId, $url]);
        return $this->remember('links', (int)$this->pdo->lastInsertId());
    }

    private function remember(string $bucket, int $id): int
    {
        $this->ids[$bucket][] = $id;
        return $id;
    }
}

final class FakeResolverProvider
{
    /** @var array<string,list<array<string,string>>> */
    private array $folders;

    public function __construct(array $folders)
    {
        $this->folders = $folders;
    }

    public function searchFolder(string $folderName, array $parentNames = []): array
    {
        $matches = $this->folders[$folderName] ?? [];
        if (!$matches) {
            return ['success' => false, 'message' => 'Folder not found'];
        }
        return ['success' => true, 'matches' => $matches];
    }

    public function generatePublicLink(string $folderId): string
    {
        return 'https://example.invalid' . $folderId;
    }
}
