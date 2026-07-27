<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class InvoiceTrackedTimeHardeningTest extends TestCase
{
    private string $create;
    private string $update;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->create = (string)file_get_contents($root . '/src/controllers/invoice/invoices_create.php');
        $this->update = (string)file_get_contents($root . '/src/controllers/invoice/invoices_update.php');
    }

    public function testInvoiceUpdatePersistsEveryCalculatedTotal(): void
    {
        self::assertStringContainsString('subtotal=?, tax_amount=?, total=?', $this->update);
        self::assertStringContainsString(
            'balance_due=GREATEST(0,?-COALESCE(amount_paid,0))',
            $this->update
        );
        self::assertMatchesRegularExpression(
            '/\\$subtotal,\\s*\\$tax,\\s*\\$total,\\s*\\$total/',
            $this->update
        );
    }

    public function testManualExtraRebuildPreservesSystemTimeAdjustmentHistory(): void
    {
        self::assertStringContainsString(
            "label NOT IN ('Tracked time','Time correction','Base-plus-overage time correction')",
            $this->update
        );
        self::assertStringNotContainsString(
            'WHERE invoice_id=? AND superseded_at IS NULL\')->execute([$id])',
            $this->update
        );
    }

    public function testTrackedTimeDerivesOneCanonicalJobAndRejectsMixedContext(): void
    {
        self::assertStringContainsString('wt.job_id', $this->create);
        self::assertStringContainsString('j.project_id job_project_id', $this->create);
        self::assertStringContainsString('count($canonicalJobIds) > 1', $this->create);
        self::assertStringContainsString('count($contextProjectIds) > 1', $this->create);
        self::assertStringContainsString('$jobId = (int)$derivedJob[\'id\'];', $this->create);
        self::assertStringContainsString(
            'The selected tracked time spans multiple Jobs.',
            $this->create
        );
        self::assertStringContainsString(
            '$e instanceof DomainException',
            $this->create
        );
        self::assertStringContainsString(
            "urlencode(\$message)",
            $this->create
        );
    }
}
