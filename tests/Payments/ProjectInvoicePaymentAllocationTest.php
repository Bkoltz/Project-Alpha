<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/project_invoice_billing.php';

use PHPUnit\Framework\TestCase;

final class ProjectInvoicePaymentAllocationTest extends TestCase
{
    private PDO $pdo;
    private array $ids = [];
    private array $previousAppConfig = [];

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
        } catch (Throwable $error) {
            $this->markTestSkipped('MySQL backend unavailable: ' . $error->getMessage());
        }
        $this->previousAppConfig = $GLOBALS['appConfig'] ?? [];
        $GLOBALS['appConfig'] = [
            'payment_receipts_enabled' => false,
            'company_admin_email' => '',
            'stripe_secret_key' => '',
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['appConfig'] = $this->previousAppConfig;
        if (!isset($this->pdo)) return;
        foreach (array_reverse($this->ids['payments'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM payments WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['project_invoice_payments'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM project_invoice_payments WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['project_invoice_items'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM project_invoice_items WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['project_invoices'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM project_invoices WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['invoices'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['projects'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['clients'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['organizations'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM organizations WHERE id=?')->execute([$id]);
        }
    }

    public function testManualParentPaymentAllocatesAcrossEveryChildOnceWithPaymentDate(): void
    {
        [$projectInvoiceId, $childIds] = $this->fixture();

        $parentId = project_invoice_record_manual_payment(
            $this->pdo, $projectInvoiceId, 100.00, 'check', 'QA-100', 'Aggregate test', '2026-08-15'
        );
        self::assertNotNull($parentId);
        $this->ids['project_invoice_payments'][] = $parentId;
        $this->rememberChildPayments($parentId);

        $rows = $this->allocations($parentId);
        self::assertCount(2, $rows);
        self::assertSame($childIds, array_map('intval', array_column($rows, 'invoice_id')));
        self::assertSame([60.0, 40.0], array_map('floatval', array_column($rows, 'amount')));
        self::assertSame(['2026-08-15', '2026-08-15'], array_column($rows, 'payment_date'));
        $parent = $this->pdo->prepare('SELECT status,payment_method FROM project_invoice_payments WHERE id=?');
        $parent->execute([$parentId]);
        self::assertSame(['status' => 'succeeded', 'payment_method' => 'check'], $parent->fetch(PDO::FETCH_ASSOC));

        self::assertTrue(project_invoice_allocate_payment(
            $this->pdo, $projectInvoiceId, 100.00, 'check', 'QA-100', 'Replay', $parentId, '2026-08-16'
        ));
        self::assertCount(2, $this->allocations($parentId), 'Replaying the same parent payment must not allocate twice.');
        self::assertSame('paid', $this->projectInvoiceStatus($projectInvoiceId));
        self::assertSame(['paid', 'paid'], $this->childStatuses($childIds));
    }

    public function testDuplicateStripeEventsReuseParentAndDoNotDuplicateChildAllocations(): void
    {
        [$projectInvoiceId] = $this->fixture();
        $intentId = 'pi_test_' . bin2hex(random_bytes(6));
        $event = [
            'id' => $intentId,
            'amount_received' => 10000,
            'metadata' => ['pa_project_invoice_id' => (string)$projectInvoiceId, 'original_amount' => '100.00'],
        ];

        self::assertTrue(project_invoice_record_stripe_payment($this->pdo, $event));
        self::assertTrue(project_invoice_record_stripe_payment($this->pdo, $event));

        $parent = $this->pdo->prepare('SELECT id,status FROM project_invoice_payments WHERE stripe_payment_intent_id=?');
        $parent->execute([$intentId]);
        $row = $parent->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $parentId = (int)$row['id'];
        $this->ids['project_invoice_payments'][] = $parentId;
        $this->rememberChildPayments($parentId);
        self::assertSame('succeeded', $row['status']);
        self::assertCount(2, $this->allocations($parentId));
        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM payments WHERE project_invoice_payment_id=' . $parentId)->fetchColumn());
    }

    private function fixture(): array
    {
        $token = bin2hex(random_bytes(5));
        $this->pdo->prepare('INSERT INTO organizations (name) VALUES (?)')->execute(['Aggregate QA ' . $token]);
        $orgId = $this->remember('organizations', (int)$this->pdo->lastInsertId());
        $this->pdo->prepare('INSERT INTO clients (name,email,organization_id) VALUES (?,?,?)')
            ->execute(['Aggregate Client ' . $token, $token . '@example.invalid', $orgId]);
        $clientId = $this->remember('clients', (int)$this->pdo->lastInsertId());
        $this->pdo->prepare('INSERT INTO projects (name,client_id,organization_id,invoice_billing_period) VALUES (?,?,?,"monthly")')
            ->execute(['Aggregate Project ' . $token, $clientId, $orgId]);
        $projectId = $this->remember('projects', (int)$this->pdo->lastInsertId());

        $childIds = [];
        foreach ([[60.00, '2026-07-01'], [40.00, '2026-07-02']] as $index => [$total, $dueDate]) {
            $this->pdo->prepare(
                'INSERT INTO invoices (client_id,project_id,organization_id,doc_number,status,subtotal,total,amount_paid,balance_due,due_date,finalized_at,collection_mode)
                 VALUES (?,?,?,?,"unpaid",?,?,0,?,?,NOW(),"project_aggregate")'
            )->execute([$clientId, $projectId, $orgId, 910000 + $index, $total, $total, $total, $dueDate]);
            $childIds[] = $this->remember('invoices', (int)$this->pdo->lastInsertId());
        }

        $this->pdo->prepare(
            'INSERT INTO project_invoices (project_id,organization_id,primary_client_id,doc_number,status,billing_period_start,billing_period_end,total,balance_due,finalized_at)
             VALUES (?,?,?,910100,"unpaid","2026-07-01","2026-07-31",100,100,NOW())'
        )->execute([$projectId, $orgId, $clientId]);
        $projectInvoiceId = $this->remember('project_invoices', (int)$this->pdo->lastInsertId());
        foreach ($childIds as $index => $childId) {
            $total = $index === 0 ? 60.00 : 40.00;
            $this->pdo->prepare(
                'INSERT INTO project_invoice_items (project_invoice_id,invoice_id,invoice_total,amount_due_at_generation) VALUES (?,?,?,?)'
            )->execute([$projectInvoiceId, $childId, $total, $total]);
            $this->remember('project_invoice_items', (int)$this->pdo->lastInsertId());
        }
        return [$projectInvoiceId, $childIds];
    }

    private function allocations(int $parentId): array
    {
        $stmt = $this->pdo->prepare('SELECT invoice_id,amount,payment_date FROM payments WHERE project_invoice_payment_id=? ORDER BY id');
        $stmt->execute([$parentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function rememberChildPayments(int $parentId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM payments WHERE project_invoice_payment_id=?');
        $stmt->execute([$parentId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $this->remember('payments', (int)$id);
    }

    private function projectInvoiceStatus(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM project_invoices WHERE id=?');
        $stmt->execute([$id]);
        return (string)$stmt->fetchColumn();
    }

    private function childStatuses(array $ids): array
    {
        $stmt = $this->pdo->prepare('SELECT status FROM invoices WHERE id IN (?,?) ORDER BY id');
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function remember(string $table, int $id): int
    {
        $this->ids[$table][] = $id;
        return $id;
    }
}
