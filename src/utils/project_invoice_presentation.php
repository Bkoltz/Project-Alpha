<?php
declare(strict_types=1);

require_once __DIR__ . '/document_pricing_adjustments.php';

/** Display only: statement amounts remain the saved billing snapshots. */
function project_invoice_item_total_rows(array $item, ?array $pricingSnapshot = null, array $adjustments = []): array
{
    $money = static fn(float $value): string => pricing_currency_amount(abs($value), 'USD', $value < 0);
    $subtotal = (float)$item['subtotal'];
    $rows = [['label' => 'Subtotal', 'value' => $money($subtotal)]];
    $inheritedDiscount = (int)($pricingSnapshot['adjustment_minor'] ?? 0) / 100;
    if ($inheritedDiscount > 0) {
        $rows[] = ['label' => 'Pricing adjustment', 'value' => $money(-$inheritedDiscount)];
    }
    $discountBasis = $pricingSnapshot !== null ? (int)$pricingSnapshot['adjusted_minor'] / 100 : $subtotal;
    $discountValue = max(0.0, (float)$item['discount_value']);
    $discountType = (string)$item['discount_type'];
    $discount = match ($discountType) {
        'percent' => $discountBasis * min(100.0, $discountValue) / 100,
        // Legacy invoices permit a fixed discount above the subtotal; the
        // authoritative pricing engine caps it to the adjusted basis.
        'fixed' => $pricingSnapshot !== null ? min($discountBasis, $discountValue) : $discountValue,
        default => 0.0,
    };
    if ($pricingSnapshot !== null) {
        $discount = round($discount, 2);
    }
    if ($discount > 0) {
        $label = 'Discount';
        if ($discountType === 'percent') {
            $label .= ' (' . rtrim(rtrim(number_format(min(100.0, $discountValue), 2, '.', ''), '0'), '.') . '%)';
        }
        $rows[] = ['label' => $label, 'value' => $money(-$discount)];
    }
    $adjustmentTotals = ['charge' => 0.0, 'credit' => 0.0];
    foreach ($adjustments as $adjustment) {
        $type = (string)($adjustment['adjustment_type'] ?? '');
        if (isset($adjustmentTotals[$type])) {
            $adjustmentTotals[$type] += max(0.0, (float)$adjustment['amount']);
        }
    }
    $taxRate = (float)$item['tax_percent'];
    $taxAmount = (float)$item['tax_amount'];
    // Some legacy contract-generated invoices saved a taxed total but left
    // tax_amount at zero. Show the calculated tax only if it reconciles with
    // that saved total; this must never change an invoice's billable amount.
    if ($taxAmount == 0.0 && $taxRate > 0) {
        $calculatedTax = max(0.0, $discountBasis - $discount) * $taxRate / 100;
        $expectedTotal = max(0.0, $discountBasis - $discount + $calculatedTax + $adjustmentTotals['charge'] - $adjustmentTotals['credit']);
        if (abs(round($expectedTotal, 2) - (float)$item['current_total']) < 0.005) {
            $taxAmount = round($calculatedTax, 2);
        }
    }
    $rows[] = [
        'label' => 'Tax' . ($taxRate > 0 ? ' (' . number_format($taxRate, 2) . '%)' : ''),
        'value' => $money($taxAmount),
    ];
    foreach (['charge' => 'Invoice charge', 'credit' => 'Invoice credit'] as $type => $label) {
        if ($adjustmentTotals[$type] > 0) {
            $rows[] = ['label' => $label, 'value' => $money($adjustmentTotals[$type] * ($type === 'credit' ? -1 : 1))];
        }
    }
    $displayedTotal = round($subtotal, 2) - round($inheritedDiscount, 2) - round($discount, 2)
        + round($taxAmount, 2) + round($adjustmentTotals['charge'], 2) - round($adjustmentTotals['credit'], 2);
    $rounding = (int)round(((float)$item['current_total'] - $displayedTotal) * 100);
    if (abs($rounding) === 1) {
        $rows[] = ['label' => 'Rounding', 'value' => $money($rounding / 100)];
    }
    $changed = round((float)$item['current_total'], 2) !== round((float)$item['invoice_total'], 2);
    if ($changed) {
        $rows[] = ['label' => 'Current invoice total', 'value' => $money((float)$item['current_total'])];
    }
    $rows[] = [
        'label' => $changed ? 'Invoice total at statement creation' : 'Invoice total',
        'value' => $money((float)$item['invoice_total']),
        'total' => true,
    ];
    if ((float)$item['amount_paid_at_generation'] > 0) {
        $rows[] = ['label' => 'Paid before this statement', 'value' => $money(-(float)$item['amount_paid_at_generation'])];
    }
    return $rows;
}
