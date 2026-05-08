<?php

namespace App\services;

use App\data_transfer_objects\contract\ContractData;
use App\data_transfer_objects\interfaces\DepositValues;
use App\data_transfer_objects\invoice\InvoiceData;
use App\data_transfer_objects\quote\QuoteData;
use App\data_transfer_objects\ItemData;
use App\services\quotes\QuoteService;
use App\utils\enum\DocumentType;

class FinancialService
{
    public static function updateQuoteFinancialData(QuoteData $quoteData, ?ItemData $items): void
    {
        $documentType = QuoteService::documentTypeFromData($quoteData);
        self::applyFinancials($quoteData, $documentType, $items);
    }

    public static function updateContractFinancialData(DocumentType $documentType, ContractData $contractData, ?ItemData $items): void
    {
        self::applyFinancials($contractData, $documentType, $items);
    }

    public static function updateInvoiceFinancialData(DocumentType $documentType, InvoiceData $invoiceData, ?ItemData $items): void
    {
        self::applyFinancials($invoiceData, $documentType, $items);
    }

    private static function applyFinancials(QuoteData|ContractData|InvoiceData $data, DocumentType $documentType, ?ItemData $items): void
    {
        $data->subtotal = FinancialCalculator::calculateSubtotal($items);
        $data->deposit_amount = FinancialCalculator::calculateDepositAmount($data->deposit_type, $data->deposit_value, $data->subtotal);
        $data->discount_amount = FinancialCalculator::calculateDiscountAmount($data->discount_type, $data->discount_value, $data->subtotal);
        $data->tax_value = FinancialCalculator::calculateTaxAmount($data->discount_value, $data->tax_percent, $data->subtotal);
        $data->total = FinancialCalculator::calculateTotal($documentType, $data->pricing_type, $data->price_per_invoice, $data->discount_amount, $data->tax_value, $data->subtotal);
    }
}
