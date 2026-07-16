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

    public function testClientFieldsUseAjaxTypeaheadAcrossTimeEntryForms(): void
    {
        $view = (string)file_get_contents($this->root . '/src/views/pages/workforce/time.php');
        $script = (string)file_get_contents($this->root . '/public/assets/js/workforce.js');

        self::assertGreaterThanOrEqual(4, substr_count($view, 'data-workforce-client-combobox'));
        self::assertStringNotContainsString('<select class="input" name="client_id"', $view);
        self::assertStringContainsString('Type to search clients', $view);
        self::assertStringContainsString("resource=clients&q=' + encodeURIComponent(query)", $script);
        self::assertStringContainsString("registerPage('workforce/time'", $script);
        self::assertStringContainsString("event.key === 'ArrowDown'", $script);
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
        self::assertStringContainsString('DocumentRevisionService::snapshotAndSave', $service);
        self::assertStringContainsString('tax_amount=?', $service);
        self::assertLessThan(
            strpos($service, 'DocumentRevisionService::snapshotAndSave'),
            strpos($service, 'invoice_refresh_payment_totals')
        );
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
