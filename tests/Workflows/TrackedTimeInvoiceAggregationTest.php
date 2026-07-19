<?php

declare(strict_types=1);

use App\Services\WorkTimeInvoiceLinkService;
use PHPUnit\Framework\TestCase;

final class TrackedTimeInvoiceAggregationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testEveryMutableDraftInvoiceCanReceiveConfirmedTime(): void
    {
        $timekeeping = (string)file_get_contents(
            $this->root . '/src/Modules/Timekeeping/TimekeepingService.php'
        );
        $linker = (string)file_get_contents(
            $this->root . '/src/services/WorkTimeInvoiceLinkService.php'
        );
        $timeView = (string)file_get_contents(
            $this->root . '/src/views/pages/workforce/time.php'
        );
        $invoiceEdit = (string)file_get_contents(
            $this->root . '/src/views/pages/invoice/invoices-edit.php'
        );
        $workforceAction = (string)file_get_contents(
            $this->root . '/src/controllers/workforce/action.php'
        );

        self::assertStringContainsString("WHERE i.status='draft' AND i.finalized_at IS NULL", $timekeeping);
        self::assertStringNotContainsString("WHERE i.billing_mode='hourly'", $timekeeping);
        self::assertStringContainsString('i.doc_number invoice_number', $timekeeping);
        self::assertStringContainsString("WHERE id=? AND status='draft' AND finalized_at IS NULL", $linker);
        self::assertStringContainsString('The time entry and invoice must belong to the same client.', $linker);
        self::assertStringContainsString('DocumentPolicy::assertMutable', $linker);
        self::assertStringContainsString('$availableInvoices = array_values(array_filter(', $timeView);
        self::assertStringContainsString('foreach ($availableInvoices as $invoice)', $timeView);
        self::assertStringContainsString('name="invoice_id" data-workforce-invoice', $timeView);
        self::assertStringContainsString('Add confirmed time to this draft', $invoiceEdit);
        self::assertStringContainsString("t.status='approved' AND t.workflow_status='confirmed'", $invoiceEdit);
        self::assertStringContainsString('name="return_to" value="invoice-edit"', $invoiceEdit);
        self::assertStringContainsString('workforce_link_preselected_invoice', $workforceAction);
        self::assertStringContainsString("=== 'invoice-edit'", $workforceAction);
    }

    public function testTrackedTimeLinesUseExistingMappingsAndAggregateMatchingSources(): void
    {
        $linker = (string)file_get_contents(
            $this->root . '/src/services/WorkTimeInvoiceLinkService.php'
        );

        self::assertStringContainsString('matchingTrackedTimeLine', $linker);
        self::assertStringContainsString("a.status='invoiced' AND a.treatment='hourly'", $linker);
        self::assertStringContainsString('t.work_type_id <=> ?', $linker);
        self::assertStringContainsString('COALESCE(jwc.item_library_id,(', $linker);
        self::assertStringContainsString('jwc2.job_id=t.job_id AND jwc2.work_type_id=t.work_type_id', $linker);
        self::assertStringContainsString('a.rate=? AND a.currency=?', $linker);
        self::assertStringContainsString('array_chunk($tokens, 3)', $linker);
        self::assertStringContainsString("implode(\"\\n\", \$descriptionLines)", $linker);
        self::assertStringContainsString('SET item_library_id=?,item=?,description=?,quantity=?,hours=?,unit_price=?,line_total=?', $linker);
        self::assertStringContainsString('WHERE id=? AND billed=0', $linker);
        self::assertStringContainsString('balance_due=GREATEST', $linker);
        self::assertStringContainsString('DocumentRevisionService::snapshotAndSave', $linker);
        self::assertStringContainsString("'time_entry.invoice_linked'", $linker);
    }

    public function testTrackedTimeDescriptionTokenUsesLocalDateAndReadableDuration(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('The SQLite PDO driver is unavailable.');
        }
        $service = new WorkTimeInvoiceLinkService(new PDO('sqlite::memory:'));
        $timezone = new ReflectionProperty($service, 'displayTimezone');
        $timezone->setValue($service, new DateTimeZone('America/Chicago'));
        $formatter = new ReflectionMethod($service, 'formatTimeToken');

        self::assertSame(
            '07-17-2026 × 1h 15m',
            $formatter->invoke($service, '2026-07-17 15:00:00', 4500)
        );
        self::assertSame(
            '07-17-2026 × 45m',
            $formatter->invoke($service, '2026-07-17 15:00:00', 2700)
        );
    }
}
