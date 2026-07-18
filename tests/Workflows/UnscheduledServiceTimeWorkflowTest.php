<?php

declare(strict_types=1);

use App\Services\UnscheduledServiceJobService;
use PHPUnit\Framework\TestCase;

final class UnscheduledServiceTimeWorkflowTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testTimeCaptureIsServiceFirstAndKeepsUnclassifiedAsAnException(): void
    {
        $view = (string)file_get_contents($this->root . '/src/views/pages/workforce/time.php');
        $script = (string)file_get_contents($this->root . '/public/assets/js/workforce.js');

        self::assertStringContainsString('name="service_item_id"', $view);
        self::assertStringContainsString('name="catalog_work_component_id"', $view);
        self::assertStringContainsString('Unclassified / no Service', $view);
        self::assertStringContainsString('data-workforce-unclassified-warning', $view);
        self::assertStringContainsString('data-workforce-service-activity', $view);
        self::assertStringContainsString('function filterServiceActivities()', $script);
        self::assertStringContainsString("option.dataset.workTypeId", $script);
        self::assertStringContainsString("base_overage: 'included_fixed'", $script);
    }

    public function testUnscheduledServiceCreatesOrReopensARealJobWithSnapshots(): void
    {
        self::assertTrue(class_exists(UnscheduledServiceJobService::class));
        self::assertTrue(method_exists(UnscheduledServiceJobService::class, 'prepare'));
        self::assertTrue(method_exists(UnscheduledServiceJobService::class, 'completeForTimeEntry'));

        $source = (string)file_get_contents($this->root . '/src/services/UnscheduledServiceJobService.php');
        self::assertStringContainsString("'unscheduled_time'", $source);
        self::assertStringContainsString('client_billing_treatment_snapshot', $source);
        self::assertStringContainsString('client_billing_rate_snapshot', $source);
        self::assertStringContainsString("status='active',completed_at=NULL", $source);
        self::assertStringContainsString("status='completed',completed_at=UTC_TIMESTAMP(6)", $source);
        self::assertStringContainsString("'fixed_price_included', 'base_overage'", $source);
        self::assertStringContainsString('$component[\'unit_price\']', $source);
    }

    public function testTimeEntryAndServiceJobAreSavedInOneTransaction(): void
    {
        $source = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/TimekeepingService.php');

        self::assertStringContainsString('withServiceContext', $source);
        self::assertStringContainsString('completeForTimeEntry($id)', $source);
        self::assertStringContainsString('completeForTimeEntry($entryId)', $source);
        self::assertLessThan(
            strpos($source, 'INSERT INTO work_time_entries'),
            strpos($source, 'withServiceContext($userId, $input, $manageAll)')
        );
    }

    public function testMigrationSupportsClientlessJobsAndPaymentJobLinks(): void
    {
        $migration = (string)file_get_contents($this->root . '/database/migrations/0050_unified_service_activity_workflow.sql');

        self::assertStringContainsString('client_billing_treatment', $migration);
        self::assertStringContainsString('job_origin', $migration);
        self::assertStringContainsString('unscheduled_time', $migration);
        self::assertStringContainsString('payments', $migration);
        self::assertStringContainsString('job_id', $migration);
        self::assertStringContainsString('ON DELETE SET NULL', $migration);
    }
}
