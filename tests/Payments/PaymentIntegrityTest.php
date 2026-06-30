<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/invoice_lifecycle.php';

use PHPUnit\Framework\TestCase;

final class PaymentIntegrityTest extends TestCase
{
    private PDO $pdo;
    private array $ids = [];

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
        } catch (Throwable $error) {
            $this->markTestSkipped('MySQL backend unavailable: ' . $error->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        foreach (array_reverse($this->ids['public_links'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM public_links WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['payments'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM payments WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['invoices'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['contracts'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM contracts WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['clients'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['organizations'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM organizations WHERE id = ?')->execute([$id]);
        }
    }

    public function testDirectPaymentCannotOverpayAndRefreshesInvoiceBalance(): void
    {
        $orgId = $this->insertOrganization();
        $clientId = $this->insertClient($orgId);
        $invoiceId = $this->insertInvoice($orgId, $clientId, 100.00);
        $publicLinkId = $this->insertInvoicePublicLink($invoiceId);

        $first = invoice_record_locked_payment($this->pdo, $invoiceId, 40.00, 'cash', null, null, [
            'organization_id' => $orgId,
            'source' => 'test',
        ]);
        $this->ids['payments'][] = (int)$first['payment_id'];

        self::assertSame('partial', $first['status']);
        self::assertEqualsWithDelta(40.00, $first['amount_paid'], 0.005);
        self::assertEqualsWithDelta(60.00, $first['balance_due'], 0.005);
        self::assertSame(0, $this->publicLinkRevoked($publicLinkId), 'Partial payment should not revoke invoice links.');

        $this->expectException(RuntimeException::class);
        try {
            invoice_record_locked_payment($this->pdo, $invoiceId, 70.00, 'cash', null, null, [
                'organization_id' => $orgId,
                'source' => 'test',
            ]);
        } finally {
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM payments WHERE invoice_id = ?');
            $count->execute([$invoiceId]);
            self::assertSame(1, (int)$count->fetchColumn(), 'Rejected overpayment must not insert a payment.');
        }
    }

    public function testFullPaymentMarksInvoicePaidRevokesLinkAndCompletesContractWhenRequested(): void
    {
        $orgId = $this->insertOrganization();
        $clientId = $this->insertClient($orgId);
        $contractId = $this->insertContract($orgId, $clientId);
        $invoiceId = $this->insertInvoice($orgId, $clientId, 100.00, $contractId);
        $publicLinkId = $this->insertInvoicePublicLink($invoiceId);

        $result = invoice_record_locked_payment($this->pdo, $invoiceId, 100.00, 'check', '1001', 'Paid in full', [
            'organization_id' => $orgId,
            'complete_contract_when_paid' => true,
            'source' => 'test',
        ]);
        $this->ids['payments'][] = (int)$result['payment_id'];

        self::assertSame('paid', $result['status']);
        self::assertEqualsWithDelta(100.00, $result['amount_paid'], 0.005);
        self::assertEqualsWithDelta(0.00, $result['balance_due'], 0.005);
        self::assertSame(1, $this->publicLinkRevoked($publicLinkId), 'Paid invoices should revoke public invoice links.');

        $status = $this->pdo->prepare('SELECT status FROM contracts WHERE id = ?');
        $status->execute([$contractId]);
        self::assertSame('completed', (string)$status->fetchColumn());
    }

    public function testDepositControllersUseCentralizedPaymentGuardrails(): void
    {
        $deposit = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/contract/contract_deposit_received.php');
        self::assertStringContainsString('require_record_ownership', $deposit);
        self::assertStringContainsString('invoice_record_locked_payment', $deposit);
        self::assertStringContainsString('Deposit exceeds the outstanding invoice balance', $deposit);
        self::assertStringNotContainsString('INSERT INTO payments', $deposit);
    }

    public function testQuoteApprovalsStoreCalculatedContractDepositAmount(): void
    {
        $approval = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/quote/quote_approve.php');
        $publicApproval = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/public_view/public_quote_action.php');

        foreach ([$approval, $publicApproval] as $source) {
            self::assertStringContainsString('$contractDepositAmount', $source);
            self::assertStringContainsString("max(0, min(100, \$depositValue)) * \$quoteTotal / 100", $source);
            self::assertStringContainsString("min(max(0, \$depositValue), \$quoteTotal)", $source);
        }
    }

    private function insertOrganization(): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO organizations (name) VALUES (?)');
        $stmt->execute(['Payment Integrity ' . bin2hex(random_bytes(4))]);
        return $this->remember('organizations', (int)$this->pdo->lastInsertId());
    }

    private function insertClient(int $orgId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO clients (name, email, organization_id) VALUES (?, ?, ?)');
        $stmt->execute(['Payment Client ' . bin2hex(random_bytes(4)), 'pay-' . bin2hex(random_bytes(4)) . '@example.invalid', $orgId]);
        return $this->remember('clients', (int)$this->pdo->lastInsertId());
    }

    private function insertContract(int $orgId, int $clientId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO contracts (client_id, organization_id, status, total, deposit_type, deposit_amount, deposit_paid) VALUES (?, ?, "pending", 100, "none", 0, 0)');
        $stmt->execute([$clientId, $orgId]);
        return $this->remember('contracts', (int)$this->pdo->lastInsertId());
    }

    private function insertInvoice(int $orgId, int $clientId, float $total, ?int $contractId = null): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO invoices
                (client_id, contract_id, organization_id, status, subtotal, total, amount_paid, balance_due, finalized_at, collection_mode)
            VALUES (?, ?, ?, "unpaid", ?, ?, 0, ?, NOW(), "direct")
        ');
        $stmt->execute([$clientId, $contractId, $orgId, $total, $total, $total]);
        return $this->remember('invoices', (int)$this->pdo->lastInsertId());
    }

    private function insertInvoicePublicLink(int $invoiceId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO public_links (token, document_type, document_id, revoked) VALUES (?, "invoice", ?, 0)');
        $stmt->execute([bin2hex(random_bytes(16)), $invoiceId]);
        return $this->remember('public_links', (int)$this->pdo->lastInsertId());
    }

    private function publicLinkRevoked(int $publicLinkId): int
    {
        $stmt = $this->pdo->prepare('SELECT revoked FROM public_links WHERE id = ?');
        $stmt->execute([$publicLinkId]);
        return (int)$stmt->fetchColumn();
    }

    private function remember(string $bucket, int $id): int
    {
        $this->ids[$bucket][] = $id;
        return $id;
    }
}
