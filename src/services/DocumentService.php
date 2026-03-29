<?php

namespace App\services;

use App\data_transfer_objects\QuoteData;
use App\data_transfer_objects\ContractData;
use App\data_transfer_objects\InvoiceData;
use APp\data_transfer_objects\ItemData;
use App\data_transfer_objects\TransferObject;
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
        $this->createContractFromTransferData($quoteData, $itemData);
        $this->createInvoiceFromTransferData($quoteData, $itemData);
    }

    public function createInvoiceFromContract(ContractData $contractData, ItemData $items) : void {
        $this->createInvoiceFromTransferData($contractData, $items);
    }
  

    public function voidContractAndFullDoc(int $id) : void {
        $this->contractService->voidContract($id);
        $this->invoiceService->voidInvoice($id);
    }

    public function payDepositFullDoc(int $id) : void {
        $depositAmount = $this->contractService->payDeposit($id);
        $this->invoiceService->payDeposit($id, $depositAmount);
    }

    public function denyContractAndFullDoc(int $id) {
        $this->contractService->denyContract($id);
        $this->invoiceService->denyInvoice($id);
    }

    public function completeContractAndFullDoc(int $id) {
        $this->contractService->completeContract($id);
        $this->invoiceService->setInvoiceDueDate($id); //Set based off AppConfig
    }

    private function createContractFromTransferData(TransferObject $object, ItemData $items)
    {
        $contractData = ContractData::fromArray($object->toArray());
        $this->contractService->createContract($contractData, $items);
    }

    private function createInvoiceFromTransferData(TransferObject $object, ItemData $items)
    {
        $invoiceData = InvoiceData::fromArray($object->toArray());
        $this->invoiceService->createInvoice($invoiceData, $items);
    }
}
