<?php

namespace App\services\quotes;

class QuotesFinances
{
    public static function calculateFinancialData(array $pageData): array
    {
        $items = $pageData['items'] ?? [];
        $discount_type = $pageData['discount_type'] ?? 'percent';
        $discount_value =  $pageData['discount_value'] ?? 0;
        $tax_percent = $pageData['tax_percent'] ?? 0;

        $subtotal = QuotesFinances::getSubtotal($items);
        $discount_amount = QuotesFinances::getDiscountAmount($discount_type, $discount_value, $subtotal);
        $tax_amount = QuotesFinances::getTaxAmount($subtotal, $discount_amount, $tax_percent);
        $total = QuotesFinances::getTotal($subtotal, $discount_amount, $tax_amount);

        return [
            'discount_amount' => $discount_amount,
            'total' => $total,
            'tax_amount' => $tax_amount,
            'subtotal' => $subtotal
        ];
    }

    private static function getDiscountAmount(string $discount_type, float $discount_value, float $subtotal): float
    {
        $discount_amount = 0.0;

        if ($discount_type === 'percent') {
            $discount_amount = max(0.0, min(100.0, $discount_value)) * $subtotal / 100.0;
        } elseif ($discount_type === 'fixed') {
            $discount_amount = max(0.0, $discount_value);
        }

        return $discount_amount;
    }

    private static function getTotal(float $subtotal, float $discount_amount, float $tax_amount): float
    {
        return max(0.0, $subtotal - $discount_amount + $tax_amount);
    }

    private static function getTaxAmount(float $subtotal, float $discount_amount, float $tax_percent): float
    {
        return max(0.0, $tax_percent) * max(0.0, $subtotal - $discount_amount) / 100.0;
    }

    private  static function getSubtotal(array $items): float
    {
        $subtotal = 0;

        foreach ($items as $item) {
            $linePrice = $item['line_total'] ?? 0;
            $subtotal += $linePrice;
        }

        return $subtotal;
    }
}
