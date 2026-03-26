<?php

namespace App\services;

use App\data_transfer_objects\QuoteData;
use App\data_transfer_objects\ContractData;
use App\data_transfer_objects\InvoiceData;
use APp\data_transfer_objects\ItemData;
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
        $itemData = $this->quoteService->getQuoteItems($id);
        $this->createFullDocumentFromQuote($quoteData, $itemData);
    }

    public function createFullDocumentFromQuote(QuoteData $quoteData, ItemData $itemData)
    {
        $this->createContractFromQuote($quoteData, $itemData);
        $this->createInvoiceFromQuote($quoteData, $itemData);
    }

    public function createContractFromQuote(QuoteData $quoteData, ItemData $quoteItems)
    {
        $contractData = $this->quoteToContractData($quoteData);
        $this->contractService->createContract($contractData, $quoteItems);
    }

    public function createInvoiceFromQuote(QuoteData $quoteData, ItemData $quoteItems)
    {
        $invoiceData = $this->quoteToInvoiceData($quoteData);
        $this->invoiceService->createInvoice($invoiceData, $quoteItems);
    }

    public function denyContractAndFullDoc(int $id) {
        $this->contractService->denyContract($id);
        $this->invoiceService->denyInvoice($id);
    }

    public function completeContractAndFullDoc(int $id) {
        $this->contractService->completeContract($id);
        $this->invoiceService->setInvoiceDueDate($id); //Set based off AppConfig
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
