<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProjectInvoiceBillingPolicyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/src/utils/project_invoice_billing.php';
    }

    public function testAggregateDueDateStartsAtStatementPeriodEnd(): void
    {
        self::assertSame(
            '2026-04-30',
            project_invoice_due_date_for_period(
                ['invoice_net_terms_days' => 30],
                ['net_terms_days' => 15],
                '2026-03-31'
            )
        );
        self::assertSame(
            '2026-04-15',
            project_invoice_due_date_for_period(
                ['invoice_net_terms_days' => ''],
                ['net_terms_days' => 15],
                '2026-03-31'
            )
        );
    }

    public function testUndeliverableMonthlyStatementsStayDraftAndDeliveryDoesNoRuntimeDdl(): void
    {
        $billing = (string)file_get_contents($this->root . '/src/utils/project_invoice_billing.php');
        $generator = (string)file_get_contents($this->root . '/src/controllers/project/project_invoice_generate.php');

        self::assertStringContainsString('$shouldFinalize = $finalize;', $billing);
        self::assertStringContainsString('$validRecipientCount === 0', $billing);
        self::assertStringContainsString('COALESCE(i.collection_mode, "direct") = "project_aggregate"', $billing);
        self::assertStringNotContainsString('ALTER TABLE public_links', $billing);
        self::assertStringContainsString('No project invoice emails were sent.', $generator);
        self::assertStringNotContainsString('saved as a draft because', $generator);
    }

    public function testRecipientTargetsAreIndependentAndManualRetriesHonorQueuedAddress(): void
    {
        $migration = (string)file_get_contents($this->root . '/database/migrations/0070_project_invoice_recipients.sql');
        $billing = (string)file_get_contents($this->root . '/src/utils/project_invoice_billing.php');
        $notifications = (string)file_get_contents($this->root . '/src/utils/project_invoice_notifications.php');
        $details = (string)file_get_contents($this->root . '/src/views/pages/project/project-invoice-details.php');

        self::assertStringContainsString('organization_id INT NULL', $migration);
        self::assertStringContainsString('manual_email VARCHAR(254) NULL', $migration);
        self::assertStringContainsString('client_id IS NOT NULL AND organization_id IS NULL', $migration);
        self::assertStringContainsString("'organization:' . \$organizationId", $billing);
        self::assertStringContainsString("\$row['notification_type'] === 'manual'", $notifications);
        self::assertStringContainsString("\$queuedEmail = trim", $notifications);
        self::assertStringContainsString('name="recipient_keys[]"', $details);
        self::assertStringContainsString('saved project invoice recipients', $details);
    }

    public function testAutoEmailRecipientValidationAcceptsOnlyCurrentlyDeliverableTargets(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, email TEXT, archived INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE TABLE organizations (id INTEGER PRIMARY KEY, general_email TEXT)');
        $pdo->exec("INSERT INTO clients (id,email,archived) VALUES (1,'valid@example.test',0),(2,'invalid',0),(3,'archived@example.test',1)");
        $pdo->exec("INSERT INTO organizations (id,general_email) VALUES (10,'company@example.test'),(11,'')");

        self::assertFalse(project_invoice_has_deliverable_recipient_config($pdo, [], [], []));
        self::assertFalse(project_invoice_has_deliverable_recipient_config($pdo, [2, 3], [], [11]));
        self::assertTrue(project_invoice_has_deliverable_recipient_config($pdo, [1], [], []));
        self::assertTrue(project_invoice_has_deliverable_recipient_config($pdo, [], ['manual@example.test'], []));
        self::assertTrue(project_invoice_has_deliverable_recipient_config($pdo, [], [], [10]));
    }

    public function testControllersAndCronSurfaceAutoEmailConfigurationAndDeliveryFailures(): void
    {
        $create = (string)file_get_contents($this->root . '/src/controllers/project/projects_create.php');
        $update = (string)file_get_contents($this->root . '/src/controllers/project/projects_update.php');
        $billing = (string)file_get_contents($this->root . '/src/utils/project_invoice_billing.php');
        $cron = (string)file_get_contents($this->root . '/src/cron/generate_recurring_invoices.php');

        foreach ([$create, $update] as $controller) {
            self::assertStringContainsString('$projectInvoiceAutoEmail &&', $controller);
            self::assertStringContainsString('Automatic project invoice email requires at least one valid recipient.', $controller);
        }
        self::assertStringContainsString('function project_invoice_generate_due_monthly_result', $billing);
        self::assertStringContainsString("'delivery_failed' => 0", $billing);
        self::assertStringContainsString('has no valid saved recipient', $billing);
        self::assertStringContainsString('project_invoice_generate_due_monthly_result($pdo, $appConfig)', $cron);
        self::assertStringContainsString("\$errors += \$projectBillingResult['delivery_pending'] + \$projectBillingResult['delivery_failed']", $cron);
        self::assertStringContainsString('project delivery {$projectBillingResult[\'delivered\']} sent/', $cron);
    }
}
