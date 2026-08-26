<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/project_invoice_presentation.php';
require_once dirname(__DIR__, 2) . '/src/utils/invoice_numbers.php';

final class ProjectInvoiceItemPresentationTest extends TestCase
{
    private function item(array $overrides = []): array
    {
        return array_replace([
            'invoice_id' => 13, 'invoice_doc_number' => 13, 'invoice_type' => 'regular',
            'client_name' => 'Contact <One>', 'invoice_date' => '2026-08-24',
            'subtotal' => '350.00', 'discount_type' => 'none', 'discount_value' => '0.00',
            'tax_percent' => '5.50', 'tax_amount' => '19.25', 'current_total' => '369.25',
            'invoice_total' => '369.25', 'amount_paid_at_generation' => '0.00',
            'amount_due_at_generation' => '369.25',
        ], $overrides);
    }

    public function testUndiscountedInvoiceShowsTaxAndNoDiscountRow(): void
    {
        $rows = array_column(project_invoice_item_total_rows($this->item()), 'value', 'label');
        self::assertSame([
            'Subtotal' => '$350.00', 'Tax (5.50%)' => '$19.25',
            'Invoice total' => '$369.25', 'Included in this statement' => '$369.25',
        ], $rows);
    }

    public function testPercentageDiscountShowsRateAndAmount(): void
    {
        $item = $this->item(['discount_type' => 'percent', 'discount_value' => '10', 'tax_amount' => '17.33',
            'current_total' => '332.33', 'invoice_total' => '332.33', 'amount_due_at_generation' => '332.33']);
        $rows = array_column(project_invoice_item_total_rows($item), 'value', 'label');
        self::assertSame('-$35.00', $rows['Discount (10%)']);
        self::assertSame('$17.33', $rows['Tax (5.50%)']);
        self::assertSame('$332.33', $rows['Invoice total']);
    }

    public function testFixedDiscountAndPriorPaymentsRemainDistinct(): void
    {
        $item = $this->item(['discount_type' => 'fixed', 'discount_value' => '50', 'tax_amount' => '16.50',
            'current_total' => '316.50', 'invoice_total' => '316.50',
            'amount_paid_at_generation' => '100', 'amount_due_at_generation' => '216.50',
            'current_paid' => '316.50']);
        $rows = array_column(project_invoice_item_total_rows($item), 'value', 'label');
        self::assertSame('-$50.00', $rows['Discount']);
        self::assertSame('-$100.00', $rows['Paid before this statement']);
        self::assertSame('$216.50', $rows['Included in this statement']);
    }

    public function testInheritedDiscountPrecedesManualDiscountAndKeepsPrivateMetadataOut(): void
    {
        $item = $this->item(['subtotal' => '100', 'discount_type' => 'percent', 'discount_value' => '10',
            'tax_percent' => '5', 'tax_amount' => '3.60', 'current_total' => '78.60',
            'invoice_total' => '78.60', 'amount_due_at_generation' => '78.60']);
        $snapshot = ['adjustment_minor' => 2000, 'adjusted_minor' => 8000, 'adjustment_name' => 'PRIVATE agreement'];
        $adjustments = [['adjustment_type' => 'charge', 'amount' => '5', 'reason' => 'PRIVATE note'],
            ['adjustment_type' => 'credit', 'amount' => '2']];
        $rows = project_invoice_item_total_rows($item, $snapshot, $adjustments);
        self::assertSame([
            'Subtotal' => '$100.00', 'Pricing adjustment' => '-$20.00', 'Discount (10%)' => '-$8.00',
            'Tax (5.00%)' => '$3.60', 'Invoice charge' => '$5.00', 'Invoice credit' => '-$2.00',
            'Invoice total' => '$78.60', 'Included in this statement' => '$78.60',
        ], array_column($rows, 'value', 'label'));
        self::assertStringNotContainsString('PRIVATE', json_encode($rows));
    }

    public function testInvoiceEditsDoNotChangeTheStatementSnapshot(): void
    {
        $item = $this->item(['subtotal' => '400', 'tax_amount' => '22', 'current_total' => '422']);
        $rows = array_column(project_invoice_item_total_rows($item), 'value', 'label');
        self::assertSame('$422.00', $rows['Current invoice total']);
        self::assertSame('$369.25', $rows['Invoice total at statement creation']);
        self::assertSame('$369.25', $rows['Included in this statement']);
    }

    public function testLegacyTaxIsShownOnlyWhenItReconcilesToSavedTotal(): void
    {
        $item = $this->item(['tax_amount' => '0']);
        $rows = array_column(project_invoice_item_total_rows($item), 'value', 'label');
        self::assertSame('$19.25', $rows['Tax (5.50%)']);
        $item['current_total'] = '350';
        $rows = array_column(project_invoice_item_total_rows($item), 'value', 'label');
        self::assertSame('$0.00', $rows['Tax (5.50%)']);
    }

    public function testTaxExemptFullyDiscountedInvoice(): void
    {
        $item = $this->item(['discount_type' => 'percent', 'discount_value' => '100',
            'tax_percent' => '0', 'tax_amount' => '0', 'current_total' => '0',
            'invoice_total' => '0', 'amount_due_at_generation' => '0']);
        $rows = array_column(project_invoice_item_total_rows($item), 'value', 'label');
        self::assertSame('-$350.00', $rows['Discount (100%)']);
        self::assertSame('$0.00', $rows['Tax']);
        self::assertSame('$0.00', $rows['Included in this statement']);
    }

    public function testLegacyFractionalCentDiscountReconcilesWithoutChangingSavedTotals(): void
    {
        foreach (['0.50', '0'] as $storedTax) {
            $item = $this->item(['subtotal' => '10.01', 'discount_type' => 'percent', 'discount_value' => '10',
                'tax_amount' => $storedTax, 'current_total' => '9.50', 'invoice_total' => '9.50',
                'amount_due_at_generation' => '9.50']);
            $rows = array_column(project_invoice_item_total_rows($item), 'value', 'label');
            self::assertSame('-$1.00', $rows['Discount (10%)']);
            self::assertSame('$0.50', $rows['Tax (5.50%)']);
            self::assertSame('-$0.01', $rows['Rounding']);
            self::assertSame('$9.50', $rows['Invoice total']);
        }
    }

    public function testSectionRendersSingleHeadingEscapedIndentedDetailsAndAlternatingBackgrounds(): void
    {
        $item = $this->item();
        $lines = [['item' => 'Video <package>', 'description' => "First line\nSecond & line", 'quantity' => 1,
            'billing_unit' => 'each', 'unit_price' => 350, 'line_total' => 350]];
        $first = $this->renderSection($item, $lines, 0);
        $second = $this->renderSection($item, [], 1);
        self::assertSame(1, substr_count($first, 'Invoice I-13'));
        self::assertStringContainsString('Contact &lt;One&gt;', $first);
        self::assertStringContainsString('Video &lt;package&gt;', $first);
        self::assertStringContainsString('Second &amp; line', $first);
        self::assertStringContainsString('Aug 24, 2026', $first);
        self::assertStringContainsString('margin-left:12px', $first);
        self::assertStringContainsString('background:#ffffff', $first);
        self::assertStringContainsString('background:#f3f4f6', $second);
        self::assertStringContainsString('No itemized details available.', $second);
        self::assertStringContainsString('Tax (5.50%)', $second);
    }

    private function renderSection(array $item, array $lines, int $invoiceSectionIndex): string
    {
        $invoiceSectionTotalRows = project_invoice_item_total_rows($item);
        ob_start();
        require dirname(__DIR__, 2) . '/src/views/components/project_invoice_item.php';
        return (string)ob_get_clean();
    }
}
