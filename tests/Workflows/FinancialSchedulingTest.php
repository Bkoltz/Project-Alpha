<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FinancialSchedulingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testScheduleSchemaSupportsAuditAndExpenseReports(): void
    {
        $migration = file_get_contents($this->root . '/database/migrations/036_scheduled_financial_reports.sql');
        self::assertStringContainsString('report_type', (string)$migration);
        self::assertStringContainsString('filters JSON', (string)$migration);
    }

    public function testScheduleHandlerScopesMutationsToActiveOrganization(): void
    {
        $handler = file_get_contents($this->root . '/src/controllers/financial/audit_schedule_handler.php');
        self::assertStringContainsString('get_active_org_id()', (string)$handler);
        self::assertStringContainsString('id=? AND organization_id=?', (string)$handler);
        self::assertStringContainsString('$requestedType === \'expense\'', (string)$handler);
    }

    public function testCronGeneratesExpenseScheduleArtifacts(): void
    {
        $cron = file_get_contents($this->root . '/src/cron/process_audit_schedules.php');
        self::assertStringContainsString('processExpenseSchedule', (string)$cron);
        self::assertStringContainsString('/var/www/config/reports/', (string)$cron);
        self::assertStringContainsString('Expense Report', (string)$cron);
    }

    public function testAuditAndOrganizationScriptsInitializeAfterAjaxNavigation(): void
    {
        $audit = file_get_contents($this->root . '/public/assets/js/audit-logic.js');
        $organization = file_get_contents($this->root . '/public/assets/js/organization-view-logic.js');
        self::assertStringContainsString("document.addEventListener('pageLoaded'", (string)$audit);
        self::assertStringContainsString("document.addEventListener('pageLoaded'", (string)$organization);
    }
}
