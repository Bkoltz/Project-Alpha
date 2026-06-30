<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OneTimePaymentPolicyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testAutoChargeIsNotScheduled(): void
    {
        $crontab = file_get_contents($this->root . '/cron/crontab');
        self::assertIsString($crontab);
        self::assertStringNotContainsString('auto_charge_recurring.php', $crontab);
    }

    public function testLegacyAutoChargeFailsClosedInProduction(): void
    {
        putenv('APP_ENV=production');
        putenv('AUTOPAY_BETA_ENABLED=true');
        $output = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root . '/src/cron/auto_charge_recurring.php') . ' 2>&1', $output, $code);
        putenv('APP_ENV');
        putenv('AUTOPAY_BETA_ENABLED');
        self::assertSame(78, $code, implode("\n", $output));
    }

    public function testCheckoutDoesNotRequestReusablePaymentMethod(): void
    {
        $service = file_get_contents($this->root . '/src/services/StripeService.php');
        self::assertIsString($service);
        self::assertStringNotContainsString('setup_future_usage', $service);
    }

    public function testQuoteApprovalCreatesDraftInvoice(): void
    {
        $approval = file_get_contents($this->root . '/src/controllers/quote/quote_approve.php');
        $publicApproval = file_get_contents($this->root . '/src/controllers/public_view/public_quote_action.php');
        self::assertStringContainsString("'draft'", (string)$approval);
        self::assertStringContainsString("'draft'", (string)$publicApproval);
    }

    public function testPublicCheckoutRequiresFinalizationAndDirectCollection(): void
    {
        $checkout = file_get_contents($this->root . '/src/controllers/stripe/stripe_checkout.php');
        self::assertStringContainsString("empty(\$invoice['finalized_at'])", (string)$checkout);
        self::assertStringContainsString("collection_mode", (string)$checkout);
        self::assertStringContainsString('Idempotency-Key', (string)file_get_contents($this->root . '/src/services/StripeService.php'));
    }

    public function testProjectPaymentsAllocateOnlyToChildInvoices(): void
    {
        $billing = file_get_contents($this->root . '/src/utils/project_invoice_billing.php');
        self::assertStringContainsString('project_invoice_payment_id', (string)$billing);
        self::assertStringContainsString('INSERT INTO payments', (string)$billing);
        self::assertStringNotContainsString('INSERT INTO payments (client_id, project_invoice_id', (string)$billing);
    }

    public function testNoAutoPayControlIsRendered(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root . '/src/views'));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            self::assertDoesNotMatchRegularExpression('/auto[ -]?pay|auto[ -]?charge/i', (string)file_get_contents($file->getPathname()), $file->getPathname());
        }
    }

    public function testDocumentDeliveryHandlersEnforceRecordOwnership(): void
    {
        $email = file_get_contents($this->root . '/src/controllers/email_send.php');
        $onDemand = file_get_contents($this->root . '/src/controllers/contract/on_demand_invoice_generate.php');
        $publicLink = file_get_contents($this->root . '/src/controllers/public_link_create.php');

        self::assertStringContainsString('require_record_ownership', (string)$email);
        self::assertStringContainsString('require_record_ownership', (string)$onDemand);
        self::assertStringContainsString('can_access_record', (string)$publicLink);
        self::assertStringContainsString("empty(\$doc['finalized_at'])", (string)$publicLink);
    }
}
