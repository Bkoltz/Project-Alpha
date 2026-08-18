<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/payment_receipts.php';

use PHPUnit\Framework\TestCase;

final class PaymentEmailDeliveryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('The SQLite PDO driver is unavailable.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-08-18 12:00:00');
        $this->pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT, email TEXT, archived INTEGER DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE invoices (
            id INTEGER PRIMARY KEY, doc_number INTEGER, invoice_type TEXT,
            recipient_presentation_mode TEXT, client_id INTEGER
        )');
        $this->pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, job_code TEXT)');
        $this->pdo->exec('CREATE TABLE processor_payment_transactions (
            id INTEGER PRIMARY KEY, payment_id INTEGER, payer_name TEXT, payer_email TEXT
        )');
        $this->pdo->exec('CREATE TABLE payments (
            id INTEGER PRIMARY KEY, invoice_id INTEGER, job_id INTEGER, processor_transaction_id INTEGER,
            client_id INTEGER, amount REAL, payment_date TEXT, payment_method TEXT,
            reference_number TEXT, status TEXT
        )');
        $this->pdo->exec('CREATE TABLE payment_receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, payment_id INTEGER UNIQUE, invoice_id INTEGER,
            receipt_number TEXT UNIQUE, public_token TEXT UNIQUE, amount REAL, email_to TEXT,
            emailed_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');
        $this->pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, name TEXT, client_id INTEGER)');
        $this->pdo->exec('CREATE TABLE organizations (id INTEGER PRIMARY KEY, name TEXT, general_email TEXT)');
        $this->pdo->exec('CREATE TABLE project_invoices (
            id INTEGER PRIMARY KEY, project_id INTEGER, primary_client_id INTEGER,
            doc_number INTEGER, status TEXT, total REAL
        )');
        $this->pdo->exec('CREATE TABLE project_invoice_payments (
            id INTEGER PRIMARY KEY, project_invoice_id INTEGER, amount REAL,
            payment_date TEXT, status TEXT
        )');
        $this->pdo->exec('CREATE TABLE project_clients (
            project_id INTEGER, client_id INTEGER, is_primary_billing INTEGER, sort_order INTEGER
        )');
        $this->pdo->exec('CREATE TABLE project_invoice_recipients (
            id INTEGER PRIMARY KEY, project_id INTEGER, client_id INTEGER, organization_id INTEGER, manual_name TEXT,
            manual_email TEXT, recipient_key TEXT, sort_order INTEGER
        )');
    }

    public function testDirectReceiptUsesStableKeyAndStrictFailureCanRetry(): void
    {
        $this->pdo->exec("INSERT INTO clients VALUES (1,'Client Contact','client@example.test',0)");
        $this->pdo->exec("INSERT INTO invoices VALUES (10,42,'regular','named',1)");
        $this->pdo->exec("INSERT INTO payments VALUES (100,10,NULL,NULL,1,125.50,'2026-08-18','stripe',NULL,'succeeded')");

        $failedOptions = [];
        try {
            payment_receipt_issue(
                $this->pdo,
                100,
                ['payment_receipts_enabled' => 1],
                true,
                static function (string $to, string $subject, string $body, array $options) use (&$failedOptions): array {
                    $failedOptions = $options;
                    return [false, 'temporary transport failure'];
                },
                true
            );
            self::fail('Strict webhook delivery should surface a failed receipt email.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('temporary transport failure', $error->getMessage());
        }

        self::assertStringStartsWith('payment-receipt:100:', $failedOptions['message_key']);
        self::assertNull($this->pdo->query('SELECT emailed_at FROM payment_receipts WHERE payment_id=100')->fetchColumn());

        $retryOptions = [];
        $receipt = payment_receipt_issue(
            $this->pdo,
            100,
            ['payment_receipts_enabled' => 1],
            true,
            static function (string $to, string $subject, string $body, array $options) use (&$retryOptions): array {
                $retryOptions = $options;
                return [true, 'sent'];
            },
            true
        );
        self::assertNotNull($receipt);
        self::assertSame($failedOptions['message_key'], $retryOptions['message_key']);
        self::assertSame('2026-08-18 12:00:00', $this->pdo->query('SELECT emailed_at FROM payment_receipts WHERE payment_id=100')->fetchColumn());
    }

    public function testAggregateProjectPaymentEmailsOneReceiptToEachInvoiceRecipient(): void
    {
        $this->pdo->exec("INSERT INTO clients VALUES
            (1,'Primary Contact','primary@example.test',0),
            (2,'Billing Contact','billing@example.test',0)");
        $this->pdo->exec("INSERT INTO organizations VALUES (9,'Client Company','accounts@example.test')");
        $this->pdo->exec("INSERT INTO projects VALUES (5,'Monthly Services',1)");
        $this->pdo->exec("INSERT INTO project_invoices VALUES (20,5,1,77,'paid',500.00)");
        $this->pdo->exec("INSERT INTO project_invoice_payments VALUES (200,20,500.00,'2026-08-18','succeeded')");
        $this->pdo->exec('INSERT INTO project_clients VALUES (5,1,1,0),(5,2,0,1)');
        $this->pdo->exec("INSERT INTO project_invoice_recipients VALUES
            (1,5,1,NULL,NULL,NULL,'client:1',0),
            (2,5,2,NULL,NULL,NULL,'client:2',1),
            (3,5,NULL,9,NULL,NULL,'organization:9',2)");

        $sent = [];
        $count = project_payment_receipt_email_issue(
            $this->pdo,
            200,
            ['payment_receipts_enabled' => 1],
            static function (string $to, string $subject, string $body, array $options) use (&$sent): array {
                $sent[] = compact('to', 'subject', 'body', 'options');
                return [true, 'sent'];
            },
            true
        );

        self::assertSame(3, $count);
        self::assertSame(
            ['primary@example.test', 'billing@example.test', 'accounts@example.test'],
            array_column($sent, 'to')
        );
        self::assertStringContainsString('Client Company (Company email)', $sent[2]['body']);
        self::assertStringContainsString('project invoice PI-77', $sent[0]['subject']);
        self::assertStringContainsString('$500.00', $sent[0]['body']);
        self::assertStringStartsWith('project-payment-receipt:200:', $sent[0]['options']['message_key']);
        self::assertNotSame($sent[0]['options']['message_key'], $sent[1]['options']['message_key']);
    }

    public function testBlankClientEmailFallsBackToProcessorPayerEmail(): void
    {
        $this->pdo->exec("INSERT INTO clients VALUES (1,'','',0)");
        $this->pdo->exec("INSERT INTO invoices VALUES (10,42,'regular','named',1)");
        $this->pdo->exec("INSERT INTO payments VALUES (100,10,NULL,9,1,125.50,'2026-08-18','stripe',NULL,'succeeded')");
        $this->pdo->exec("INSERT INTO processor_payment_transactions
            (id,payment_id,payer_name,payer_email) VALUES (9,100,'Card Payer','payer@example.test')");

        $sent = [];
        payment_receipt_issue(
            $this->pdo,
            100,
            ['payment_receipts_enabled' => 1],
            true,
            static function (string $to, string $subject, string $body, array $options) use (&$sent): array {
                $sent[] = compact('to', 'subject', 'body', 'options');
                return [true, 'sent'];
            },
            true
        );

        self::assertCount(1, $sent);
        self::assertSame('payer@example.test', $sent[0]['to']);
        self::assertStringContainsString('Hello Card Payer', $sent[0]['body']);
        self::assertSame('payer@example.test', $this->pdo->query(
            'SELECT email_to FROM payment_receipts WHERE payment_id=100'
        )->fetchColumn());
    }

    public function testWebhookAndReconciliationRetryExistingPaymentDeliveries(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'src/controllers/webhook/stripe_checkout_completed.php',
            'src/controllers/webhook/stripe_payment_succeeded.php',
            'src/utils/stripe_reconciliation_import.php',
        ] as $path) {
            $source = (string)file_get_contents($root . '/' . $path);
            self::assertStringContainsString('existingPaymentId', $source, $path);
            self::assertStringContainsString('payment_receipt_issue(', $source, $path);
            self::assertStringContainsString('$existingPaymentId', $source, $path);
            self::assertStringContainsString('false,', $source, $path);
            self::assertStringContainsString('true', $source, $path);
        }

        $projectBilling = (string)file_get_contents($root . '/src/utils/project_invoice_billing.php');
        self::assertStringContainsString('project_payment_receipt_email_issue', $projectBilling);
        self::assertStringContainsString("(\$projectPayment['status'] ?? '') === 'succeeded'", $projectBilling);
        self::assertStringContainsString("'project-payment:' . \$projectPaymentId", $projectBilling);
    }

    public function testCustomerReceiptAttemptIsNotBlockedByAdminDeliveryFailure(): void
    {
        $this->pdo->exec("INSERT INTO clients VALUES (1,'Client Contact','client@example.test',0)");
        $this->pdo->exec("INSERT INTO invoices VALUES (10,42,'regular','named',1)");
        $this->pdo->exec("INSERT INTO payments VALUES (100,10,NULL,NULL,1,125.50,'2026-08-18','stripe',NULL,'succeeded')");
        $attempts = [];
        try {
            payment_email_attempt_all(
                static function () use (&$attempts): void {
                    $attempts[] = 'admin';
                    throw new RuntimeException('admin transport unavailable');
                },
                function () use (&$attempts): void {
                    $attempts[] = 'customer_receipt';
                    payment_receipt_issue(
                        $this->pdo,
                        100,
                        ['payment_receipts_enabled' => 1],
                        true,
                        static fn(): array => [true, 'sent'],
                        true
                    );
                }
            );
            self::fail('The combined delivery should report the admin failure after attempting the receipt.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('admin transport unavailable', $error->getMessage());
        }
        self::assertSame(['admin', 'customer_receipt'], $attempts);
        self::assertSame(
            '2026-08-18 12:00:00',
            $this->pdo->query('SELECT emailed_at FROM payment_receipts WHERE payment_id=100')->fetchColumn()
        );

        $root = dirname(__DIR__, 2);
        foreach ([
            'src/controllers/webhook/stripe_checkout_completed.php',
            'src/controllers/webhook/stripe_payment_succeeded.php',
            'src/utils/stripe_reconciliation_import.php',
            'src/utils/project_invoice_billing.php',
        ] as $path) {
            self::assertStringContainsString(
                'payment_email_attempt_all(',
                (string)file_get_contents($root . '/' . $path),
                $path
            );
        }
    }
}
