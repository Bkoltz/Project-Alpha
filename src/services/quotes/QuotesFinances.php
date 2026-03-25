<?php

namespace App\services\quotes;

use App\data_transfer_objects\QuoteData;
use App\data_transfer_objects\QuoteItemsData;

class QuotesFinances
{
    public static function calculateFinancialData(QuoteData $quoteData, QuoteItemsData $quoteItems): QuoteData
    {
        $discount_type = $quoteData->discount_type;
        $discount_value =  $quoteData->discount_value;
        $tax_percent = $quoteData->tax_percent;

        $subtotal = QuotesFinances::getSubtotal($quoteItems);
        $discount_amount = QuotesFinances::getDiscountAmount($discount_type, $discount_value, $subtotal);
        $tax_amount = QuotesFinances::getTaxAmount($subtotal, $discount_amount, $tax_percent);
        $total = QuotesFinances::getTotal($subtotal, $discount_amount, $tax_amount);

        $quoteData->subtotal = $subtotal;
        $quoteData->discount_value = $discount_amount;
        $quoteData->tax_percent = $tax_amount;
        $quoteData->total = $total;

        return $quoteData;
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

    private  static function getSubtotal(QuoteItemsData $quoteItems): float
    {
        $subtotal = 0;

        for ($i = 0; $i < count($quoteItems->item); $i++) {
            $quantity = $quoteItems->quantity[$i];
            $unitPrice = $quoteItems->unit_price[$i];

            $subtotal += $quantity * $unitPrice;
            $quoteItems->line_total[$i] = $quantity * $unitPrice;
        }

        return $subtotal;
    }
}
