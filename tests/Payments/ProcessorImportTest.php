<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProcessorImportTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testProcessorImportMigrationAddsGenericSchema(): void
    {
        $sql = $this->read('database/migrations/0016_processor_payment_imports.sql');

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS processor_payment_transactions', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS processor_webhook_events', $sql);
        self::assertStringContainsString('ALTER TABLE payments MODIFY COLUMN client_id INT NULL', $sql);
        self::assertStringContainsString('ALTER TABLE payments MODIFY COLUMN payment_method VARCHAR(50)', $sql);
        self::assertStringContainsString('processor_provider VARCHAR(50)', $sql);
        self::assertStringContainsString('processor_payment_id VARCHAR(255)', $sql);
        self::assertStringContainsString('processor_gross_amount DECIMAL(12,2)', $sql);
        self::assertStringContainsString('processor_fee_amount DECIMAL(12,2)', $sql);
    }

    public function testBillingSettingsExposeIndependentProcessorImportToggles(): void
    {
        $view = $this->read('src/views/pages/settings/billing.php');
        $handler = $this->read('src/controllers/settings_handler.php');
        $config = $this->read('src/config/app.php');

        foreach (['processor_import_standalone_income', 'processor_import_auto_create_clients'] as $key) {
            self::assertStringContainsString($key, $view);
            self::assertStringContainsString($key, $handler);
            self::assertStringContainsString($key, $config);
        }
    }

    public function testGenericImportServiceEncodesToggleAndClientRules(): void
    {
        $service = $this->read('src/services/PaymentProcessorImportService.php');

        self::assertStringContainsString('class PaymentProcessorImportService', $service);
        self::assertStringContainsString('standaloneImportEnabled', $service);
        self::assertStringContainsString('autoCreateClientsEnabled', $service);
        self::assertStringContainsString('Net payout and processor fee are required', $service);
        self::assertStringContainsString('matchOrCreateClient', $service);
        self::assertStringContainsString('payer_name', $service);
        self::assertStringContainsString('payer_email', $service);
        self::assertStringContainsString('processor_provider, processor_payment_id, processor_transaction_id', $service);
    }

    public function testStripeReconciliationUsesGenericStandaloneImportFallback(): void
    {
        $cron = $this->read('src/cron/stripe_reconciliation.php');
        $stripe = $this->read('src/services/StripeService.php');
        $webhook = $this->read('src/controllers/webhook/stripe_payment_succeeded.php');

        self::assertStringContainsString('PaymentProcessorImportService::importStandalone', $cron);
        self::assertStringContainsString('normalizePaymentIntentForImport', $stripe);
        self::assertStringContainsString('charges.data.balance_transaction', $stripe);
        self::assertStringContainsString('PaymentProcessorImportService::importStandalone', $webhook);
    }

    public function testStandaloneProcessorIncomeIsVisibleWithoutClient(): void
    {
        $paymentsList = $this->read('src/views/pages/payments/payments-list.php');
        $receipts = $this->read('src/utils/payment_receipts.php');
        $publicReceipt = $this->read('src/controllers/public_view/payment_receipt.php');

        self::assertStringContainsString('LEFT JOIN clients c ON c.id=p.client_id', $paymentsList);
        self::assertStringContainsString('Processor income', $paymentsList);
        self::assertStringContainsString('LEFT JOIN clients c ON c.id=p.client_id', $receipts);
        self::assertStringContainsString('COALESCE(c.email,ppt.payer_email)', $receipts);
        self::assertStringContainsString('LEFT JOIN clients c ON c.id=p.client_id', $publicReceipt);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->root . '/' . $relativePath);
        self::assertIsString($contents, $relativePath);
        return $contents;
    }
}
