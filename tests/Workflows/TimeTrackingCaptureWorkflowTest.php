<?php

declare(strict_types=1);

use App\Services\WorkTimeInvoiceLinkService;
use PHPUnit\Framework\TestCase;

final class TimeTrackingCaptureWorkflowTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testUnifiedTimeEntryUsesSearchableContextAndAjaxClientLookup(): void
    {
        $view = (string)file_get_contents($this->root . '/src/views/pages/workforce/time.php');
        $script = (string)file_get_contents($this->root . '/public/assets/js/workforce.js');

        self::assertGreaterThanOrEqual(2, substr_count($view, 'data-workforce-client-combobox'));
        self::assertSame(1, substr_count($view, 'data-workforce-record-form'));
        self::assertStringNotContainsString('<select class="input" name="client_id"', $view);
        self::assertStringContainsString('Type to search clients', $view);
        self::assertStringContainsString('data-workforce-search-select', $view);
        self::assertStringContainsString('data-workforce-option-filter', $view);
        self::assertStringContainsString("resource=clients&q=' + encodeURIComponent(query)", $script);
        self::assertStringContainsString("registerPage('workforce/time'", $script);
        self::assertStringContainsString("event.key === 'ArrowDown'", $script);
        self::assertStringContainsString('function initSearchSelect', $script);
    }

    public function testRecordTimeModesUseCompatibleActionsAndSeparateOutcomes(): void
    {
        $view = (string)file_get_contents($this->root . '/src/views/pages/workforce/time.php');
        $script = (string)file_get_contents($this->root . '/public/assets/js/workforce.js');

        self::assertStringContainsString('Record time', $view);
        self::assertStringContainsString('value="duration"', $view);
        self::assertStringContainsString('value="timer"', $view);
        self::assertStringContainsString('value="exact"', $view);
        self::assertStringContainsString('name="capture_mode"', $view);
        self::assertStringContainsString('data-workforce-duration-hours', $view);
        self::assertStringContainsString('data-workforce-duration-minute-part', $view);
        self::assertStringContainsString('type="hidden" name="duration_minutes"', $view);
        self::assertStringNotContainsString('Duration (minutes)', $view);
        self::assertStringNotContainsString('<h3 class="card-title">Quick work entry</h3>', $view);
        self::assertStringNotContainsString('<h3 class="card-title">Manual entry</h3>', $view);
        self::assertStringContainsString('name="billing_treatment"', $view);
        self::assertStringContainsString('Client billing', $view);
        self::assertStringContainsString('Worker compensation', $view);
        self::assertStringContainsString('<th>Client billing</th>', $view);
        self::assertStringContainsString('<th>Worker compensation</th>', $view);
        self::assertStringContainsString("mode === 'timer' ? 'clock-in' : 'manual-create'", $script);
        self::assertStringContainsString('const minutes = (hours * 60) + minutePart', $script);
        self::assertStringContainsString('startTime.value = localDateTimeValue(start)', $script);
        self::assertStringContainsString("billingTreatment.value === 'ready' ? '1' : '0'", $script);
        self::assertStringContainsString("registerPage('workforce/time'", $script);
    }

    public function testConfirmedTimeCanBeLinkedToAnInvoiceWithAnAuditedRevision(): void
    {
        self::assertTrue(class_exists(WorkTimeInvoiceLinkService::class));
        self::assertTrue(method_exists(WorkTimeInvoiceLinkService::class, 'link'));

        $service = (string)file_get_contents($this->root . '/src/services/WorkTimeInvoiceLinkService.php');
        self::assertStringContainsString('DocumentPolicy::assertMutable', $service);
        self::assertStringContainsString("'Tracked time'", $service);
        self::assertStringContainsString("'hour',0", str_replace('\\\'', "'", $service));
        self::assertStringContainsString('WorkTimeBillingContextService', $service);
        self::assertStringContainsString('invoice_adjustments', $service);
        self::assertStringContainsString('affects_total,revision_number', $service);
        self::assertStringContainsString("VALUES (?,\\'charge\\',\\'Tracked time\\',?,?,?,?,0,?,?)", $service);
        self::assertStringContainsString('pricing_invoice_is_fixed_total_installment', $service);
        self::assertStringContainsString('pricing_finalize_frozen_document_revision', $service);
        self::assertStringContainsString("strtoupper((string)(\$entry['snapshot_currency'] ?? 'USD'))", $service);
        self::assertStringContainsString('DocumentRevisionService::snapshotAndSave', $service);
        self::assertStringContainsString('tax_amount=?', $service);
        self::assertStringNotContainsString('UPDATE work_approval_snapshots', $service);
    }

    public function testBillingRateIsDeferredUntilTimeActuallyBecomesAnInvoiceLine(): void
    {
        $approval = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/ApprovalService.php');
        $linkService = (string)file_get_contents($this->root . '/src/services/WorkTimeInvoiceLinkService.php');
        $invoiceCreate = (string)file_get_contents($this->root . '/src/controllers/invoice/invoices_create.php');
        $invoiceScript = (string)file_get_contents($this->root . '/public/assets/js/invoices-create-logic.js');

        self::assertStringNotContainsString('A project or business billing rate is required for billable time.', $approval);
        self::assertStringContainsString('Enter the hourly billing rate to add this time to the invoice.', $linkService);
        self::assertStringContainsString('$allTimeUnpriced', $invoiceCreate);
        self::assertStringContainsString('Set an hourly billing rate before adding tracked time to the invoice.', $invoiceCreate);
        self::assertStringContainsString('Enter the hourly billing rate for this tracked time:', $invoiceScript);
        self::assertMatchesRegularExpression(
            '/window\.prompt.+?for \(const group of groups\.values\(\)\) \{.+?addItemInv/s',
            $invoiceScript
        );
    }

    public function testWorkTypeBillingDefaultsFlowIntoTimeReviewAndApproval(): void
    {
        $service = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/TimekeepingService.php');
        $approval = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/ApprovalService.php');
        $view = (string)file_get_contents($this->root . '/src/views/pages/workforce/time.php');
        $script = (string)file_get_contents($this->root . '/public/assets/js/workforce.js');

        self::assertStringContainsString('LEFT JOIN work_type_billing_defaults', $service);
        self::assertStringContainsString('data-billing-treatment=', $view);
        self::assertStringContainsString('applyWorkTypeBillingDefault', $script);
        self::assertStringContainsString('work_type_billing_rate', $approval);
        self::assertStringContainsString('$entry[\'service_activity_billing_rate\']', $approval);
        self::assertStringContainsString('?? $entry[\'work_type_billing_rate\']', $approval);
        self::assertStringContainsString('?? $settings[\'default_billing_rate\']', $approval);
    }

    public function testWorkforceActionSupportsEditAndInvoiceLinking(): void
    {
        $controller = (string)file_get_contents($this->root . '/src/controllers/workforce/action.php');

        self::assertStringContainsString("\$action === 'link-invoice'", $controller);
        self::assertStringContainsString("if (!\$manageAll || !user_can", $controller);
        self::assertStringContainsString("'invoices.edit'", $controller);
        self::assertStringContainsString("require_record_ownership(\$pdo, 'invoices', \$invoiceId)", $controller);
        self::assertStringContainsString('ensureOwnerProjection', $controller);
        self::assertStringContainsString('new WorkTimeInvoiceLinkService', $controller);
        self::assertStringContainsString("in_array(\$action, ['resubmit', 'edit'], true)", $controller);
        self::assertStringContainsString('reviseEntry', $controller);
    }
}
