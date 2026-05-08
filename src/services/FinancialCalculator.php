<?php

namespace App\services;

use App\utils\enum\DocumentType;
use App\data_transfer_objects\ItemData;
use Exception;

class FinancialCalculator
{
    public static function calculateDepositAmount(string $deposit_type, float $deposit_value, float $subtotal): float
    {
        $value = match (strtolower(trim($deposit_type))) {
            'percent' => min(100.0, $deposit_value) * $subtotal / 100.0,
            'fixed' => $deposit_value,
            default => 0.0
        };

        return max(0.0, $value);
    }

    public static function calculateDiscountAmount(string $discount_type, float $discount_value, float $subtotal): float
    {
        $value = match (strtolower(trim($discount_type))) {
            'percent' => min(100.0, $discount_value) * $subtotal / 100.0,
            'fixed' => $discount_value,
            default => 0.0
        };

        return max(0.0, $value);
    }

    public static function calculateTotal(DocumentType $documentType, string $pricing_type, float $price_per_invoice, float $discount_amount, float $tax_value, float $subtotal): float
    {
        $value = match ($documentType) {
            DocumentType::REGULAR => $subtotal,
            DocumentType::LONG_TERM => $price_per_invoice,
            DocumentType::ON_DEMAND => $pricing_type === 'per_invoice' ? $price_per_invoice : $subtotal,
            default => 0.0
        };

        $value -= $discount_amount + $tax_value;

        return max(0.0, $value);
    }

    public static function calculateTaxAmount(float $discount_value, float $tax_percent, float $subtotal): float
    {
        return max(0.0, $tax_percent) * max(0.0, $subtotal - $discount_value) / 100.0;
    }

    public static function calculateSubtotal(?ItemData $items): float
    {
        if ($items === null || $items->isNull());
            return 0;

        $subtotal = 0;
        for ($i = 0; $i < count($items->item); $i++) {
            $quantity = $items->quantity[$i];
            $unitPrice = $items->unit_price[$i];

            $subtotal += $quantity * $unitPrice;
            $items->line_total[$i] = $quantity * $unitPrice;
        }

        return $subtotal;
    }
}
