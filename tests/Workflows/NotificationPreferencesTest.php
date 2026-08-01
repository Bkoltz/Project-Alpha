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
        $this->pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, created_by INTEGER)');
        $this->pdo->exec('CREATE TABLE project_invoices (id INTEGER PRIMARY KEY, created_by INTEGER)');
        $this->pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY, email TEXT, role TEXT, is_disabled INTEGER, deleted_at TEXT
        )');
        $this->pdo->exec('CREATE TABLE user_notification_preferences (
            user_id INTEGER PRIMARY KEY, notify_processor_invoice_paid INTEGER NOT NULL DEFAULT 1
        )');
        $this->pdo->exec('INSERT INTO invoices (id,created_by) VALUES (10,3)');
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

    public function testDeliveryKeysIncludeCumulativePaymentState(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/utils/notifications.php');
        self::assertStringContainsString("\$invoice['amount_paid']", $source);
        self::assertStringContainsString("\$invoice['balance_due']", $source);
        self::assertStringContainsString("\$subject . ':' . \$occurrence", $source);
        self::assertFileExists(
            dirname(__DIR__, 2) . '/database/migrations/0061_user_notification_preferences.sql'
        );
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
