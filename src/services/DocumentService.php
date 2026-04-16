<?php

namespace App\services;

use App\data_transfer_objects\QuoteData;
use App\data_transfer_objects\ContractData;
use App\data_transfer_objects\InvoiceData;
use App\data_transfer_objects\ItemData;
use App\record_transfer_objects\ItemRecord;
use App\data_transfer_objects\TransferObject;
use App\services\contract\ContractService;
use App\services\invoice\InvoiceService;
use App\services\quotes\QuoteService;
use App\data_transfer_objects\ContractEditData;
use App\data_transfer_objects\ContractSignatures;
use App\data_transfer_objects\InvoiceEditData;
use App\utils\enum\DocumentType;

class DocumentService
{
    private QuoteService $quoteService;
    private ContractService $contractService;
    private InvoiceService $invoiceService;
    private MetaService $metaService;

    public function __construct(QuoteService $quoteService, ContractService $contractService, InvoiceService $invoiceService, MetaService $metaService) {
        $this->quoteService = $quoteService;
        $this->contractService = $contractService;
        $this->invoiceService = $invoiceService;
        $this->metaService = $metaService;
    }

    public function acceptQuoteAndCreateFullDoc(int $id) {
        $this->quoteService->approveQuote($id);
        
        $quoteData = $this->quoteService->getQuoteData($id);
        $itemData = $this->quoteService->getQuoteItems($id);

        $documentType = $this->quoteService->documentTypeFromData($quoteData);
        $this->createFullDocumentFromQuote($documentType, $quoteData, $itemData);
    }

    public function createFullDocumentFromQuote(DocumentType $documentType, QuoteData $quoteData, ItemData $itemData)
    {
        $this->createContractFromTransferData($documentType, $quoteData, $itemData);
        $this->createInvoiceFromTransferData($documentType, $quoteData, $itemData);
    }

    public function createInvoiceFromContract(DocumentType $documentType, ContractData $contractData, ItemData $items) : void {
        $this->createInvoiceFromTransferData($documentType, $contractData, $items);
    }

    // TODO: FIX this broken it shouldnt need documetn tyep
    public function updateContractAndInvoice(int $id, ContractEditData $contractData, ItemData $items, ContractSignatures $contractSignatures) : void {
        $invoiceData = InvoiceEditData::fromArray($contractData->toArray());
        
        $this->contractService->updateContractWithSignatures($id, DocumentType::REGULAR, $contractData, $items, $contractSignatures);
        $this->invoiceService->updateInvoice($id, $invoiceData, $items);
    }
  
    public function updateAllInvoicesItems(int $contractId, ItemData $invoiceItems): void
    {
        $itemsRecord = ItemRecord::fromArray($invoiceItems->toArray());
        $ids = $this->invoiceService->getInvoiceIdsFromContract($contractId);

        foreach ($ids as $id) {
            $this->invoiceService->updateInvoiceItems($id, $itemsRecord);
        }
    }

    public function voidContractAndFullDoc(int $id,  DocumentType $documentType) : void {
        $this->contractService->voidContract($id, $documentType);
        $this->invoiceService->voidInvoice($id);
    }

    public function payDepositFullDoc(int $id) : void {
        $documentType = $this->quoteService->documentTypeFromId($id);

        $depositAmount = $this->contractService->payFullDeposit($documentType, $id);
        $this->invoiceService->payDeposit($id, $depositAmount);
    }

    public function denyContractAndFullDoc(int $id, DocumentType $documentType) {
        $this->contractService->denyContract($id, $documentType);
        $this->invoiceService->denyInvoice($id);
    }

    public function completeContractAndFullDoc(int $id, DocumentType $documentType) {
        $this->contractService->completeContract($id, $documentType);
        $this->invoiceService->setInvoiceDueDate($id); //Set based off AppConfig
    }

    // TODO: FIX this soudlnt need stn;ad
    private function createContractFromTransferData(DocumentType $documentType, TransferObject $object, ItemData $items)
    {
        $contractData = ContractData::fromArray($object->toArray());
        $this->contractService->createContract($documentType, $contractData, $items);
    }

    private function createInvoiceFromTransferData(DocumentType $documentType, TransferObject $object, ItemData $items)
    {
        $invoiceData = InvoiceData::fromArray($object->toArray());
        $this->invoiceService->createInvoice($documentType, $invoiceData, $items);
    }
}
