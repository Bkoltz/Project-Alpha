<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/invoice_lifecycle.php';
require_once dirname(__DIR__, 2) . '/src/utils/payment_receipts.php';

use PHPUnit\Framework\TestCase;

final class GeneralRecipientInvoiceDatabaseTest extends TestCase
{
    private PDO $pdo;
    private array $ids = [];

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
            $column = $this->pdo->query(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema=DATABASE() AND table_name="invoices"
                   AND column_name="recipient_presentation_mode"'
            );
            if ((int)$column->fetchColumn() !== 1) {
                $this->markTestSkipped('Migration 0060 has not been applied to the MySQL test database.');
            }
        } catch (Throwable $error) {
            $this->markTestSkipped('MySQL backend unavailable: ' . $error->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        foreach (array_reverse($this->ids['payment_receipts'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM payment_receipts WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['payments'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM payments WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['public_links'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM public_links WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['invoices'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['clients'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['organizations'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM organizations WHERE id=?')->execute([$id]);
        }
    }

    public function testMigrationCreatesPresentationColumnAndIndex(): void
    {
        $column = $this->pdo->query(
            'SELECT is_nullable AS nullable_flag,column_default AS default_value FROM information_schema.columns
             WHERE table_schema=DATABASE() AND table_name="invoices"
               AND column_name="recipient_presentation_mode"'
        )->fetch(PDO::FETCH_NUM);
        $index = $this->pdo->query(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema=DATABASE() AND table_name="invoices"
               AND index_name="idx_invoices_recipient_presentation"
               AND column_name="recipient_presentation_mode"'
        );

        self::assertSame('NO', $column[0] ?? null);
        self::assertSame('named', $column[1] ?? null);
        self::assertSame(1, (int)$index->fetchColumn());
    }

    public function testFinalizePaymentAndSevenDayReceiptLifecycleIsDatabaseBacked(): void
    {
        [$orgId, $clientId] = $this->createAccountingOwner();
        $invoiceId = $this->createInvoice($orgId, $clientId, 'general');

        $first = invoice_finalize_and_create_general_recipient_link(
            $this->pdo,
            $invoiceId,
            ['net_terms_days' => 30],
            null
        );
        $second = invoice_finalize_and_create_general_recipient_link(
            $this->pdo,
            $invoiceId,
            ['net_terms_days' => 30],
            null
        );

        self::assertFalse($first['existing']);
        self::assertTrue($second['existing']);
        self::assertSame($first['token'], $second['token']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['token']);

        $links = $this->pdo->prepare('SELECT id FROM public_links WHERE document_type="invoice" AND document_id=?');
        $links->execute([$invoiceId]);
        $linkIds = array_map('intval', $links->fetchAll(PDO::FETCH_COLUMN));
        self::assertCount(1, $linkIds);
        $this->ids['public_links'] = array_merge($this->ids['public_links'] ?? [], $linkIds);

        $payment = invoice_record_locked_payment(
            $this->pdo,
            $invoiceId,
            42.00,
            'stripe',
            'pi_general_test',
            null,
            ['organization_id' => $orgId, 'source' => 'test_stripe']
        );
        $this->remember('payments', (int)$payment['payment_id']);
        self::assertSame('paid', $payment['status']);

        $invoiceStmt = $this->pdo->prepare('SELECT status,paid_at FROM invoices WHERE id=?');
        $invoiceStmt->execute([$invoiceId]);
        $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame('paid', $invoice['status']);
        self::assertNotEmpty($invoice['paid_at']);

        $link = $this->linkState($invoiceId);
        self::assertSame(0, (int)$link['revoked']);
        self::assertNull($link['redirect']);
        self::assertSame(0, (int)$link['expire_when_paid']);
        self::assertSame(
            strtotime((string)$invoice['paid_at']) + (7 * 86400),
            strtotime((string)$link['expires_at'])
        );

        self::assertNull(
            payment_receipt_issue($this->pdo, (int)$payment['payment_id'], ['payment_receipts_enabled' => true])
        );
        $receiptCount = $this->pdo->prepare('SELECT COUNT(*) FROM payment_receipts WHERE payment_id=?');
        $receiptCount->execute([(int)$payment['payment_id']]);
        self::assertSame(0, (int)$receiptCount->fetchColumn());

        // A refund clears paid_at but leaves the active token's former receipt
        // timestamp in place. A later repayment must calculate a fresh, exact
        // seven-day window from the new paid_at instead of retaining that date.
        $this->pdo->prepare('UPDATE payments SET refunded_amount=amount WHERE id=?')
            ->execute([(int)$payment['payment_id']]);
        $refunded = invoice_refresh_payment_totals($this->pdo, $invoiceId);
        self::assertSame('unpaid', $refunded['status']);
        $invoiceStmt->execute([$invoiceId]);
        self::assertEmpty(($invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: [])['paid_at'] ?? null);
        $this->pdo->prepare('UPDATE public_links SET expires_at=DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE document_type="invoice" AND document_id=?')
            ->execute([$invoiceId]);
        $this->pdo->prepare('UPDATE payments SET refunded_amount=0 WHERE id=?')
            ->execute([(int)$payment['payment_id']]);
        $repaid = invoice_refresh_payment_totals($this->pdo, $invoiceId);
        self::assertSame('paid', $repaid['status']);
        $invoiceStmt->execute([$invoiceId]);
        $repaidInvoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $repaidLink = $this->linkState($invoiceId);
        self::assertSame(
            strtotime((string)$repaidInvoice['paid_at']) + (7 * 86400),
            strtotime((string)$repaidLink['expires_at'])
        );
        self::assertGreaterThan(time(), strtotime((string)$repaidLink['expires_at']));

        $this->pdo->prepare('UPDATE invoices SET paid_at=DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE id=?')
            ->execute([$invoiceId]);
        pa_public_link_terminalize($this->pdo, 'invoice', $invoiceId, 'paid');
        $expired = $this->linkState($invoiceId);
        self::assertSame(1, (int)$expired['revoked']);
        self::assertNull($expired['redirect']);
        self::assertSame(0, (int)$expired['expire_when_paid']);
        self::assertLessThanOrEqual(time(), strtotime((string)$expired['expires_at']));
    }

    public function testOrdinaryPaidInvoiceKeepsRedirectedTerminalBehavior(): void
    {
        [$orgId, $clientId] = $this->createAccountingOwner();
        $invoiceId = $this->createInvoice($orgId, $clientId, 'named', 'unpaid');
        $token = bin2hex(random_bytes(32));
        $insert = $this->pdo->prepare(
            'INSERT INTO public_links
                (token,document_type,document_id,expires_at,expire_when_paid,revoked,redirect)
             VALUES (?,"invoice",?,NULL,1,0,NULL)'
        );
        $insert->execute([$token, $invoiceId]);
        $this->remember('public_links', (int)$this->pdo->lastInsertId());

        $payment = invoice_record_locked_payment(
            $this->pdo,
            $invoiceId,
            42.00,
            'check',
            'normal-test',
            null,
            ['organization_id' => $orgId, 'source' => 'test']
        );
        $this->remember('payments', (int)$payment['payment_id']);

        $link = $this->linkState($invoiceId);
        self::assertSame(1, (int)$link['revoked']);
        self::assertStringContainsString('reason=paid', (string)$link['redirect']);
        self::assertNotEmpty($link['expires_at']);
    }

    private function createAccountingOwner(): array
    {
        $organization = $this->pdo->prepare('INSERT INTO organizations (name) VALUES (?)');
        $organization->execute(['General Invoice Test ' . bin2hex(random_bytes(4))]);
        $orgId = $this->remember('organizations', (int)$this->pdo->lastInsertId());

        $client = $this->pdo->prepare('INSERT INTO clients (name,email,organization_id) VALUES (?,?,?)');
        $client->execute([
            'Private Accounting Client ' . bin2hex(random_bytes(4)),
            'private-' . bin2hex(random_bytes(4)) . '@example.invalid',
            $orgId,
        ]);
        $clientId = $this->remember('clients', (int)$this->pdo->lastInsertId());
        return [$orgId, $clientId];
    }

    private function createInvoice(
        int $orgId,
        int $clientId,
        string $presentationMode,
        string $status = 'draft'
    ): int {
        $finalizedAt = $status === 'draft' ? null : date('Y-m-d H:i:s');
        $insert = $this->pdo->prepare(
            'INSERT INTO invoices
                (client_id,recipient_presentation_mode,organization_id,invoice_type,collection_mode,
                 status,subtotal,total,amount_paid,balance_due,finalized_at)
             VALUES (?,?,?,"regular","direct",?,42,42,0,42,?)'
        );
        $insert->execute([$clientId, $presentationMode, $orgId, $status, $finalizedAt]);
        return $this->remember('invoices', (int)$this->pdo->lastInsertId());
    }

    private function linkState(int $invoiceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT revoked,redirect,expire_when_paid,expires_at
             FROM public_links WHERE document_type="invoice" AND document_id=? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function remember(string $bucket, int $id): int
    {
        $this->ids[$bucket][] = $id;
        return $id;
    }
}
