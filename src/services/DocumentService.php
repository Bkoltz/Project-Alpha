<?php

namespace App\services;

use App\data_transfer_objects\QuoteData;
use App\data_transfer_objects\ContractData;
use App\data_transfer_objects\InvoiceData;

use App\services\contract\ContractService;
use App\services\invoice\InvoiceService;
use App\services\quotes\QuoteService;

class DocumentService
{
    private QuoteService $quoteService;
    private ContractService $contractService;
    private InvoiceService $invoiceService;

    public function __construct(QuoteService $quoteService, ContractService $contractService, InvoiceService $invoiceService) {
        $this->quoteService = $quoteService;
        $this->contractService = $contractService;
        $this->invoiceService = $invoiceService;
    }

    public function acceptQuoteAndCreateFullDoc(int $id) {
        $this->quoteService->approveQuote($id);

        $quoteData = $this->quoteService->getQuoteData($id);
        $this->createFullDocumentFromQuote($quoteData);
    }

    public function createFullDocumentFromQuote(QuoteData $quoteData)
    {
        $this->createContractFromQuote($quoteData);
        $this->createInvoiceFromQuote($quoteData);
    }

    public function createContractFromQuote(QuoteData $quoteData)
    {
        $contractData = $this->quoteToContractData($quoteData);
        $this->contractService->createContract($contractData);
    }

    public function createInvoiceFromQuote(QuoteData $quoteData)
    {
        $invoiceData = $this->quoteToInvoiceData($quoteData);
        $this->invoiceService->createInvoice($invoiceData);
    }

    //Maps quote data to contract data
    private function quoteToContractData(QuoteData $quoteData): ContractData    
    {
        $array = $quoteData->toArray();

        return ContractData::fromArray($array);
    }

    //Maps quote data to invoice data
    private function quoteToInvoiceData(QuoteData $quoteData): InvoiceData
    {
       $array = $quoteData->toArray();

       return InvoiceData::fromArray($array);
    }
}
