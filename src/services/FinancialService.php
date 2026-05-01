<?php

namespace App\services;

use App\data_transfer_objects\DepositValues;
use App\data_transfer_objects\quote\QuoteData;
use App\data_transfer_objects\ItemData;
use App\services\quotes\QuoteService;

class FinancialService
{
    public static function updateQuoteFinancialData(QuoteData $quoteData, ?ItemData $quoteItems): void
    {
        self::getSubtotal($quoteData, $quoteItems);
        self::getDepositAmount($quoteData);
        self::getDiscountAmount($quoteData);
        self::getTaxAmount($quoteData);
        self::getTotal($quoteData);
    }

    public static function calculateDepositValue(DepositValues $contractData): float
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

    private static function getDepositAmount(QuoteData $quoteData) : float {
         $value = match (strtolower(trim($quoteData->deposit_type))) {
            'percent' => min(100.0, $quoteData->deposit_value) * $quoteData->subtotal / 100.0,
            'fixed' => $quoteData->deposit_value,
            default => 0.0
        };

        $quoteData->deposit_amount = max(0.0, $value);
        return $quoteData->deposit_amount;
    }

    private static function getDiscountAmount(QuoteData $quoteData): float
    {
        $value = match (strtolower(trim($quoteData->discount_type))) {
            'percent' => min(100.0, $quoteData->discount_value) * $quoteData->subtotal / 100.0,
            'fixed' => $quoteData->discount_value,
            default => 0.0
        };

        $quoteData->discount_amount = max(0.0, $value);
        return $quoteData->discount_amount;
    }

    private static function getTotal(QuoteData $quoteData): float
    {
        $documentType = QuoteService::documentTypeFromData($quoteData)->value;

        $value = match ($documentType) {
            'regular' => $quoteData->subtotal,
            'long-term' => $quoteData->price_per_invoice,
            'on-demand' => $quoteData->pricing_type === 'per_invoice' ? $quoteData->price_per_invoice : $quoteData->subtotal,
            default => 0.0
        };

        $value -= $quoteData->discount_amount + $quoteData->tax_value;
        $quoteData->total = max(0.0, $value);

        return $quoteData->total;
    }

    private static function getTaxAmount(QuoteData $quoteData): float
    {
        $quoteData->tax_value = max(0.0, $quoteData->tax_percent) * max(0.0, $quoteData->subtotal - $quoteData->discount_value) / 100.0;

        return $quoteData->tax_value;
    }

    private  static function getSubtotal(QuoteData $quoteData, ?ItemData $quoteItems): float
    {
        if ($quoteItems === null)
            return 0;

        $quoteData->subtotal = 0;
        for ($i = 0; $i < count($quoteItems->item); $i++) {
            $quantity = $quoteItems->quantity[$i];
            $unitPrice = $quoteItems->unit_price[$i];

            $quoteData->subtotal += $quantity * $unitPrice;
            $quoteItems->line_total[$i] = $quantity * $unitPrice;
        }

        return $quoteData->subtotal;
    }
}
