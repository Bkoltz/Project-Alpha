<?php

namespace App\services\quotes;

use App\data_transfer_objects\ItemData;
use App\data_transfer_objects\quote\QuoteData;
use App\record_transfer_objects\ItemRecord;
use App\record_transfer_objects\QuoteRecord;
use App\repositories\quotes\QuotesRepository;
use App\services\DateValidator;
use App\services\FinancialService;
use App\services\ProjectService;
use App\utils\enum\DocumentType;

class QuoteService
{
    private QuotesRepository $repository;
    private ProjectService $projectService;

    public function __construct(QuotesRepository $repository, ProjectService $projectService)
    {
        $this->repository = $repository;
        $this->projectService = $projectService;
    }

    public function createQuote(QuoteData $quoteData, ItemData $quoteItems)
    {
        $this->validateAndUpdateQuoteData($quoteData, $quoteItems);
        $quoteItems?->validate();
        
        $record = QuoteRecord::fromArray($quoteData->toArray());
        $recordItems = $quoteItems->isNull() ? null : ItemRecord::fromArray($quoteItems->toArray());

        $this->repository->createNewQuote($record, $recordItems);
    }

    public function editQuote(int $id, QuoteData $quoteData) {}

    public function documentTypeFromId(int $id): DocumentType
    {
        $data = $this->repository->getQuoteData($id);
        $data = QuoteData::fromArray($data->toArray());

        return $this->documentTypeFromData($data);
    }

    public function documentTypeFromData(QuoteData $data): DocumentType
    {
        if ($data->is_long_term == 1 && $data->is_on_demand == 0) {
            return DocumentType::LONG_TERM;
        } elseif ($data->is_long_term == 0 && $data->is_on_demand == 1) {
            return DocumentType::ON_DEMAND;
        }
        
        return DocumentType::REGULAR;
    }

    public function approveQuote(int $id)
    {
        $this->repository->approveQuote($id);
    }

    public function rejectQuote(int $id)
    {
        $this->repository->rejectQuote($id);
    }

    public function getStoredQuote(int $id): QuoteData
    {
        $data = $this->repository->getQuoteData($id);
        return QuoteData::fromArray($data->toArray());
    }

    public function getStoredQuoteItems(int $id): ?ItemData
    {
        $data = $this->repository->getQuoteItems($id);
        return ItemData::fromArray($data?->toArray());
    }

    public function getQuoteDate(int $id) : array {
        return $this->repository->getQuoteDate($id);
    }

    private function validateAndUpdateQuoteData(QuoteData $quoteData, ItemData $quoteItems): void
    {
        $this->validateQuoteData($quoteData);
        $this->updateDocumentType($quoteData);

        $quoteData->project_code ??= $this->projectService->getNextProjectCode($quoteData->client_id);
        $quoteData->created_at = date('Y-m-d H:i:s');

        FinancialService::calculateFinancialData($quoteData, $quoteItems);
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
        $quoteData->fulfillment_date = DateValidator::validateDate($quoteData->fulfillment_date);
        $quoteData->is_long_term = !empty($quoteData->is_long_term) ? 1 : 0;
        $quoteData->is_on_demand = !empty($quoteData->is_on_demand) ? 1 : 0;
        $quoteData->start_date = DateValidator::validateDate($quoteData->start_date);
        $quoteData->end_date = DateValidator::validateDate($quoteData->end_date);
        $quoteData->billing_interval_count ??= 0;
        $quoteData->billing_interval_unit ??= 'month';
        $quoteData->pricing_type ??= 'per_invoice';
        $quoteData->price_per_invoice ??= 0;
        $quoteData->scope ??= '';
        $quoteData->custom_fields ??= null;
        $quoteData->created_at = DateValidator::validateDate($quoteData->created_at);
    }
}
