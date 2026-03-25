<?php

namespace App\services\quotes;

use App\data_transfer_objects\QuoteData;
use App\data_transfer_objects\QuoteItemsData;
use App\record_transfer_objects\QuoteItemsRecord;
use App\record_transfer_objects\QuoteMetaRecord;
use App\record_transfer_objects\QuoteRecord;
use App\repositories\quotes\QuotesRepository;

class QuoteService
{
    private QuotesRepository $repository;

    public function __construct(QuotesRepository $repository)
    {
        $this->repository = $repository;
    }

    public function createQuote(QuoteData $quoteData, QuoteItemsData $quoteItems)
    {
        $this->updateQuoteData($quoteData, $quoteItems);
        $this->updateQuoteItems($quoteItems);

        $record = QuoteRecord::fromArray($quoteData->toArray());
        $recordMeta = QuoteMetaRecord::fromArray($quoteData->toArray());
        $recordItems = QuoteItemsRecord::fromArray($quoteItems->toArray());

        $this->repository->createNewQuote($record, $recordMeta, $recordItems);
    }

    public function editQuote(int $id, QuoteData $quoteData) {}

    public function approveQuote(int $id)
    {
        $this->repository->approveQuote($id);
    }

    public function rejectQuote(int $id)
    {
        $this->repository->rejectQuote($id);
    }

    //Returns quote data grabbed from the repository
    public function getQuoteData(int $id): QuoteData
    {
        $data = $this->repository->getQuoteData($id);
        return QuoteData::fromArray($data);
    }

    private function updateQuoteData(QuoteData $quoteData, QuoteItemsData $quoteItems): void
    {
        $this->validateQuoteData($quoteData);
        $this->updateProjectCode($quoteData);
        $this->updateDocumentType($quoteData);

        QuotesFinances::calculateFinancialData($quoteData, $quoteItems);
    }

    private function updateQuoteItems(QuoteItemsData $quoteItems): void
    {
        $this->validateQuoteItems($quoteItems);
        $this->calculateLineTotal($quoteItems);
    }

    private function updateProjectCode(QuoteData $quoteData): void
    {
        $quoteData->project_code ??= $this->repository->getNextProjectCode($quoteData->client_id);
    }

    private function calculateLineTotal(QuoteItemsData $quoteItems): void
    {
        for ($i = 0; $i < count($quoteItems->item); $i++) {
            $quantity = $quoteItems->quantity[$i];
            $unitPrice = $quoteItems->unit_price[$i];

            $quoteItems->line_total[$i] = $quantity * $unitPrice;
        }
    }

    private function updateDocumentType(QuoteData $quoteData): void
    {
        $quoteData->is_long_term = ($quoteData->doc_type === 'long_term') ? 1 : 0;
        $quoteData->is_on_demand = ($quoteData->doc_type === 'on_demand') ? 1 : 0;
    }

    public function validateQuoteData(QuoteData $quoteData): void
    {
        $quoteData->client_id ??= 0;
        $quoteData->project_id ??= 0;
        $quoteData->status ??= 'pending';
        $quoteData->discount_type ??= 'none';
        $quoteData->discount_value ??= 0;
        $quoteData->tax_percent ??= 0;
        $quoteData->subtotal ??= 0;
        $quoteData->total ??= 0;
        $quoteData->deposit_type ??= 'none';
        $quoteData->deposit_amount ??= 0;
        $quoteData->fulfillment_date = !empty($quoteData->fulfillment_date) ?  $quoteData->fulfillment_date : null;
        $quoteData->is_long_term = !empty($quoteData->is_long_term) ? 1 : 0;
        $quoteData->is_on_demand = !empty($quoteData->is_on_demand) ? 1 : 0;
        $quoteData->start_date = !empty($quoteData->start_date) ? $quoteData->start_date : null;
        $quoteData->end_date = !empty($quoteData->end_date) ? $quoteData->end_date : null;
        $quoteData->billing_interval_count ??= 0;
        $quoteData->billing_interval_unit ??= '';
        $quoteData->pricing_type ??= '';
        $quoteData->price_per_invoice ??= 0;
        $quoteData->scope ??= '';
        $quoteData->custom_fields ??= null;
        $quoteData->created_at = !empty($quoteData->created_at) ?  $quoteData->created_at : date('Y-m-d H:i:s');
    }

    public function validateQuoteItems(QuoteItemsData $quoteItems): void
    {
        $quoteItems->item ??= [];
        $quoteItems->description ??= [];
        $quoteItems->quantity ??= [];
        $quoteItems->unit_price ??= [];
    }

    private function sortEditData(): array
    {
        return [];
    }
}
