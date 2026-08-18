<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/notifications.php';

use PHPUnit\Framework\TestCase;

final class NotificationPreferencesTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('The SQLite PDO driver is unavailable.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec('CREATE TABLE invoices (
            id INTEGER PRIMARY KEY, created_by INTEGER, client_id INTEGER, doc_number INTEGER,
            invoice_type TEXT, project_code TEXT, total REAL, amount_paid REAL, balance_due REAL
        )');
        $this->pdo->exec('CREATE TABLE project_invoices (id INTEGER PRIMARY KEY, created_by INTEGER)');
        $this->pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY, email TEXT, role TEXT, is_disabled INTEGER, deleted_at TEXT
        )');
        $this->pdo->exec('CREATE TABLE user_notification_preferences (
            user_id INTEGER PRIMARY KEY, notify_processor_invoice_paid INTEGER NOT NULL DEFAULT 1
        )');
        $this->pdo->exec("INSERT INTO clients (id,name) VALUES (8,'Payment Client')");
        $this->pdo->exec("INSERT INTO invoices
            (id,created_by,client_id,doc_number,invoice_type,project_code,total,amount_paid,balance_due)
            VALUES (10,3,8,44,'regular','PROJECT-1',100,100,0)");
        $this->pdo->exec('INSERT INTO project_invoices (id,created_by) VALUES (20,3)');
        $this->pdo->exec("INSERT INTO users (id,email,role,is_disabled,deleted_at) VALUES
            (1,'admin@example.test','admin',0,NULL),
            (2,'owner@example.test','owner',0,NULL),
            (3,'creator@example.test','staff',0,NULL),
            (4,'other@example.test','staff',0,NULL),
            (5,'disabled@example.test','admin',1,NULL),
            (6,'not-an-email','admin',0,NULL),
            (7,'deleted@example.test','admin',0,'2026-01-01')");
        $this->pdo->exec('INSERT INTO user_notification_preferences
            (user_id,notify_processor_invoice_paid) VALUES (2,0),(3,1),(4,1)');
    }

    public function testRecipientsRespectRoleCreatorPreferenceAndDeliverability(): void
    {
        self::assertSame(
            ['admin@example.test', 'creator@example.test'],
            invoice_payment_notification_recipients($this->pdo, 10)
        );
        self::assertSame(
            ['admin@example.test', 'creator@example.test'],
            project_invoice_payment_notification_recipients($this->pdo, 20)
        );
    }

    public function testDeliveryKeysSupportStablePaymentIdentity(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/utils/notifications.php');
        self::assertStringContainsString('$paymentOccurrenceKey', $source);
        self::assertStringContainsString('strtolower($recipientEmail) . \':\' . $occurrence', $source);
        self::assertFileExists(
            dirname(__DIR__, 2) . '/database/migrations/0061_user_notification_preferences.sql'
        );
    }

    public function testRollingDeployWithoutPreferenceTableUsesDocumentCreatorAndAdmins(): void
    {
        $this->pdo->exec('DROP TABLE user_notification_preferences');
        self::assertSame(
            ['admin@example.test', 'owner@example.test', 'creator@example.test'],
            invoice_payment_notification_recipients($this->pdo, 10)
        );
        self::assertSame(
            ['admin@example.test', 'owner@example.test', 'creator@example.test'],
            project_invoice_payment_notification_recipients($this->pdo, 20)
        );
    }

    public function testStrictProcessorAlertSurfacesTransportFailureForWebhookRetry(): void
    {
        $attempts = [];
        try {
            notify_admin_invoice_paid(
                $this->pdo,
                ['notify_invoice_paid' => 1, 'notify_invoice_paid_regular' => 1],
                10,
                100.0,
                'paid',
                false,
                true,
                static function (string $to, string $subject, string $body, array $options) use (&$attempts): array {
                    $attempts[] = compact('to', 'subject', 'body', 'options');
                    return [false, 'temporary transport failure'];
                }
            );
            self::fail('Strict processor alerts must surface delivery failure.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('temporary transport failure', $error->getMessage());
        }

        self::assertCount(2, $attempts);
        self::assertSame(['admin@example.test', 'creator@example.test'], array_column($attempts, 'to'));
        foreach ($attempts as $attempt) {
            self::assertStringStartsWith('invoice-paid-processor:10:', $attempt['options']['message_key']);
        }
    }

    public function testOutOfOrderRetryKeepsOriginalPaymentDeliveryKey(): void
    {
        $deliveries = [];
        $sender = static function (string $to, string $subject, string $body, array $options) use (&$deliveries): array {
            $deliveries[] = ['to' => $to, 'key' => $options['message_key']];
            return [true, 'sent'];
        };
        $config = ['notify_invoice_paid' => 1, 'notify_invoice_paid_regular' => 1];

        notify_admin_invoice_paid($this->pdo, $config, 10, 40, 'partial', false, true, $sender, 'payment:700');
        $firstPayment = array_slice($deliveries, 0, 2);

        $this->pdo->exec('UPDATE invoices SET amount_paid=100,balance_due=0 WHERE id=10');
        notify_admin_invoice_paid($this->pdo, $config, 10, 60, 'paid', false, true, $sender, 'payment:701');
        $secondPayment = array_slice($deliveries, 2, 2);

        // Retry payment 700 after payment 701 changed the invoice totals and
        // terminal status. Its delivery identity must remain payment 700.
        notify_admin_invoice_paid($this->pdo, $config, 10, 40, 'paid', false, true, $sender, 'payment:700');
        $outOfOrderRetry = array_slice($deliveries, 4, 2);

        self::assertSame(array_column($firstPayment, 'key'), array_column($outOfOrderRetry, 'key'));
        self::assertNotSame(array_column($firstPayment, 'key'), array_column($secondPayment, 'key'));
        self::assertSame(array_column($firstPayment, 'to'), array_column($outOfOrderRetry, 'to'));
    }

    public function testPreferenceControllerRemainsAuthenticatedAndCsrfProtected(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string)file_get_contents($root . '/src/controllers/auth/account_notification_prefs.php');
        $router = (string)file_get_contents($root . '/public/index.php');
        self::assertStringContainsString("empty(\$_SESSION['user'])", $controller);
        self::assertStringContainsString('hash_equals', $controller);
        self::assertStringContainsString('account-notification-prefs', $router);
    }
}
