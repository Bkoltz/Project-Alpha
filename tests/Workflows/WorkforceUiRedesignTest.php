<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Services\WorkforceCommandRegistry;

final class WorkforceUiRedesignTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testWorkforceNavigationAndQueuesUseTheNewInformationArchitecture(): void
    {
        $header = (string)file_get_contents($this->root . '/src/views/partials/header.php');
        $review = (string)file_get_contents($this->root . '/src/views/pages/workforce/approvals.php');
        $pay = (string)file_get_contents($this->root . '/src/views/pages/workforce/pay.php');

        self::assertStringContainsString('Work Review', $header);
        self::assertStringContainsString('Earnings &amp; Pay', $header);
        foreach (['time-review', 'billing-context', 'corrections', 'pay-exceptions'] as $tab) {
            self::assertStringContainsString('data-workforce-tab="' . $tab . '"', $review);
        }
        foreach (['earnings', 'statements', 'payments', 'exports'] as $tab) {
            self::assertStringContainsString('data-workforce-tab="' . $tab . '"', $pay);
        }
        self::assertStringContainsString("tableExists('time_correction_requests')", $review);
        self::assertStringContainsString("tableExists('worker_payment_records')", $pay);
        self::assertStringContainsString("tableExists('payroll_exports')", $pay);
    }

    public function testSharedWorkforceInitializerIsIdempotentForHardAndSoftNavigation(): void
    {
        $script = (string)file_get_contents($this->root . '/public/assets/js/workforce.js');

        self::assertStringContainsString("root.dataset.workforcePageReady === '1'", $script);
        self::assertStringContainsString("initWorkforcePage.pageInitializerId = 'workforce-page'", $script);
        self::assertStringContainsString("window.ProjectAlpha.registerPage([", $script);
        self::assertStringContainsString("'workforce/approvals'", $script);
        self::assertStringContainsString("'workforce/pay'", $script);
        self::assertStringContainsString("root.removeAttribute('data-workforce-page-ready')", $script);
        self::assertStringContainsString("new URLSearchParams(window.location.search).get('tab')", $script);
    }

    public function testPayScheduleExplainsDeadlineAutomation(): void
    {
        $view = (string)file_get_contents($this->root . '/src/views/pages/settings/pay-periods.php');
        $controller = (string)file_get_contents($this->root . '/src/controllers/settings/workforce_catalog_handler.php');

        self::assertStringContainsString('name="deadline_time"', $view);
        self::assertStringContainsString('4, 2, and 1 hour', $view);
        self::assertStringContainsString('name="auto_confirm"', $view);
        self::assertStringContainsString('workforce_period_deadline_time', $controller);
        self::assertStringContainsString('workforce_period_auto_confirm', $controller);
    }

    public function testNewWorkforceFormsUseRegisteredCsrfProtectedPostContracts(): void
    {
        $time = (string)file_get_contents($this->root . '/src/views/pages/workforce/time.php');
        $review = (string)file_get_contents($this->root . '/src/views/pages/workforce/approvals.php');
        $pay = (string)file_get_contents($this->root . '/src/views/pages/workforce/pay.php');
        $views = $time . $review . $pay;
        $actions = [
            'correction-approve', 'correction-reject',
            'correction-billing-resolve', 'worker-payment-record', 'worker-payment-void',
            'payroll-export-generate', 'payroll-export-void',
        ];

        foreach ($actions as $action) {
            self::assertContains($action, WorkforceCommandRegistry::actions());
            self::assertStringContainsString('name="action" value="' . $action . '"', $views);
            self::assertSame('POST', WorkforceCommandRegistry::require($action)['method']);
            self::assertTrue(WorkforceCommandRegistry::require($action)['csrf']);
        }
        foreach (['correction-request','admin-correction-apply'] as $conditionalAction) {
            self::assertContains($conditionalAction, WorkforceCommandRegistry::actions());
            self::assertStringContainsString("'" . $conditionalAction . "'", $time);
            self::assertSame('POST', WorkforceCommandRegistry::require($conditionalAction)['method']);
            self::assertTrue(WorkforceCommandRegistry::require($conditionalAction)['csrf']);
        }
        foreach (['entry_id', 'request_id', 'decision', 'worker_profile_id', 'statement_ids[]', 'allocation_amounts[]', 'export_key', 'earning_ids[]'] as $field) {
            self::assertStringContainsString('name="' . $field . '"', $views);
        }
        self::assertGreaterThanOrEqual(count($actions), substr_count($views, 'name="csrf"'));
    }
}
