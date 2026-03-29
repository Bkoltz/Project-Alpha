<?php

namespace App\services\quotes;

use App\data_transfer_objects\ContractData;
use App\data_transfer_objects\DepositValues;
use App\data_transfer_objects\QuoteData;
use App\data_transfer_objects\ItemData;

class FinancialService
{
    public static function calculateFinancialData(QuoteData $quoteData, ItemData $quoteItems): QuoteData
    {
        $discount_type = $quoteData->discount_type;
        $discount_value =  $quoteData->discount_value;
        $tax_percent = $quoteData->tax_percent;

        $subtotal = FinancialService::getSubtotal($quoteItems);
        $discount_amount = FinancialService::getDiscountAmount($discount_type, $discount_value, $subtotal);
        $tax_amount = FinancialService::getTaxAmount($subtotal, $discount_amount, $tax_percent);
        $total = FinancialService::getTotal($subtotal, $discount_amount, $tax_amount);

        $quoteData->subtotal = $subtotal;
        $quoteData->discount_value = $discount_amount;
        $quoteData->tax_percent = $tax_amount;
        $quoteData->total = $total;

        return $quoteData;
    }

    public static function calculateDepositValue(DepositValues $contractData) : float
    {
        $depositType = $contractData->getDepositType();
        $depositValue = $contractData->getDepositAmount();
        $total = $contractData->getTotal();

        $depositCalc = 0;

        if ($depositType === 'percent') {
            $depositCalc = max(0, min(100, $depositValue)) * $total / 100;
        } elseif ($depositType === 'fixed') {
            $depositCalc = $depositValue;
        }

        return $depositCalc;
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

    private  static function getSubtotal(ItemData $quoteItems): float
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
