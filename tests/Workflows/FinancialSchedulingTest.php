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
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        self::assertStringContainsString('report_type', (string)$baseline);
        self::assertStringContainsString('filters JSON', (string)$baseline);
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
        self::assertStringContainsString("window.ProjectAlpha.registerPage('financial/audit'", (string)$audit);
        self::assertStringContainsString("window.ProjectAlpha.registerPage('organization/organization-view'", (string)$organization);
    }

    public function testAuditSchedulingIsASeparateVisibleAction(): void
    {
        $auditPage = file_get_contents($this->root . '/src/views/pages/financial/audit.php');
        self::assertStringContainsString('id="auditScheduleForm"', (string)$auditPage);
        self::assertStringContainsString('Scheduled Audit Emails', (string)$auditPage);
        self::assertStringContainsString('Save Schedule', (string)$auditPage);
    }
}
