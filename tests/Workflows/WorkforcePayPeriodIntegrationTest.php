<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WorkforcePayPeriodIntegrationTest extends TestCase
{
    private string $service;
    private string $view;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->service = (string)file_get_contents($root . '/src/services/PayPeriodService.php');
        $this->view = (string)file_get_contents($root . '/src/views/pages/workforce/pay.php');
    }

    public function testApprovedUnifiedEarningsAreThePrimaryStatementSource(): void
    {
        self::assertStringContainsString("FROM worker_earnings e", $this->service);
        self::assertStringContainsString("e.status='approved'", $this->service);
        self::assertStringContainsString('e.pay_period_id=?', $this->service);
        self::assertStringContainsString('DATE(wt.start_time)', $this->service);
        self::assertStringContainsString('DATE(wa.completed_at)', $this->service);
        self::assertStringContainsString('includeOnStatement', $this->service);
        self::assertStringContainsString('UPDATE worker_earnings SET pay_period_id=?', $this->service);
    }

    public function testLegacySourcesAreOnlyACompatibilityFallback(): void
    {
        self::assertStringContainsString(
            'NOT EXISTS (SELECT 1 FROM worker_earnings e WHERE e.work_assignment_id=wa.id)',
            $this->service
        );
        self::assertStringContainsString(
            'NOT EXISTS (SELECT 1 FROM worker_earnings e WHERE e.work_time_entry_id=s.time_entry_id)',
            $this->service
        );
        self::assertStringContainsString('Compatibility only: legacy compensation', $this->service);
    }

    public function testStatementSettlementAdvancesEarningsAndCompatibilityRowsTogether(): void
    {
        self::assertStringContainsString("SET status='settled',settled_at=UTC_TIMESTAMP(6)", $this->service);
        self::assertStringContainsString("'included','settled','statement_settled'", $this->service);
        self::assertStringContainsString("SET wa.status='settled'", $this->service);
        self::assertStringContainsString("SET a.status='paid'", $this->service);
        self::assertStringContainsString("SET compensation_state='settled'", $this->service);
    }

    public function testPayPageUsesCanonicalEarningsAndNewStatementPermissions(): void
    {
        self::assertStringContainsString('workforce.statements.manage', $this->view);
        self::assertStringContainsString('FROM worker_earnings e', $this->view);
        self::assertStringContainsString('Earnings ledger', $this->view);
        self::assertStringContainsString('Current period', $this->view);
        self::assertStringContainsString('Legacy pay records', $this->view);
        self::assertStringContainsString("JSON_EXTRACT(e.calculation_snapshot,'$.direction')", $this->view);
        self::assertStringNotContainsString('name="action" value="pay-status"', $this->view);
    }

    public function testClientBillingIsNotPresentedAsWorkerCompensation(): void
    {
        self::assertStringContainsString(
            'Client billing is tracked separately and never determines worker pay.',
            $this->view
        );
        self::assertStringNotContainsString('billing_rate', $this->view);
        self::assertStringNotContainsString('invoice_total', $this->view);
    }
}
