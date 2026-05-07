<?php

namespace App\services\invoice;

use App\config\AppConfiguration;
use App\data_transfer_objects\invoice\InvoiceEditData;
use App\data_transfer_objects\invoice\InvoiceData;
use APp\data_transfer_objects\ItemData;
use App\record_transfer_objects\InvoiceRecord;
use App\record_transfer_objects\ItemRecord;
use App\repositories\invoice\InvoiceRepository;
use App\record_transfer_objects\invoice\create_record\BaseInvoiceRecord;
use App\record_transfer_objects\invoice\create_record\LongTermInvoiceRecord;
use App\record_transfer_objects\invoice\create_record\OnDemandInvoiceRecord;
use App\record_transfer_objects\invoice\create_record\RegularInvoiceRecord;
use App\record_transfer_objects\InvoiceEditRecord;
use App\services\MetaService;
use App\services\ProjectService;
use App\utils\enum\DocumentType;
use Exception;

class InvoiceService
{
    private InvoiceRepository $repository;
    private MetaService $metaService;
    private ProjectService $projectService;

    public function __construct(InvoiceRepository $repository, MetaService $metaService, ProjectService $projectService)
    {
        $this->repository = $repository;
        $this->metaService = $metaService;
        $this->projectService = $projectService;
    }

    public function createInvoice(DocumentType $documentType, InvoiceData $invoiceData, ?ItemData $invoiceItems)
    {
        $this->updateAndValidateInvoiceData($invoiceData);
        $invoiceItems?->validate();

        $record = $this->generateInsertRecord($documentType, $invoiceData->toArray());
        $itemsRecord = ItemRecord::fromArray($invoiceItems?->toArray());

        $id = $this->repository->createInvoice($documentType, $record, $itemsRecord);
        $this->metaService->insertProjectMetaFromArray($invoiceData->toArray()); 
        $this->projectService->insertInvoiceProjectDoc($invoiceData->project_id, $id);
    }

    private function generateInsertRecord(DocumentType $documentType, array $data): BaseInvoiceRecord
    {
        return match ($documentType) {
            DocumentType::REGULAR => RegularInvoiceRecord::fromArray($data),
            DocumentType::ON_DEMAND => OnDemandInvoiceRecord::fromArray($data),
            DocumentType::LONG_TERM => LongTermInvoiceRecord::fromArray($data),
            default => throw new Exception("Invalid document type")
        };
    }

    public function updateInvoice(int $id, InvoiceData $invoiceData, ?ItemData $invoiceItems): void
    {
        $record = InvoiceEditRecord::fromArray($invoiceData->toArray());
        $recordItems = ItemRecord::fromArray($invoiceItems?->toArray());

        $this->repository->updateFullInvoice($id, $record, $recordItems);
    }

    public function updateInvoiceWithItems() : void {
        
    }

    public function getInvoiceIdsFromContract(int $contractId): array
    {
        return $this->repository->getInvoiceIdsFromContract($contractId);
    }

    public function updateInvoiceItems(int $id, ItemRecord $itemsRecord): void
    {
        $this->repository->updateInvoiceItems($id, $itemsRecord);
    }

    public function voidInvoice(int $id): void
    {
        $this->repository->voidInvoiceByContractId($id);
    }

    public function payDeposit(int $id, float $paidDeposit): void
    {
        $this->repository->payDeposit($id, $paidDeposit);
    }

    public function setInvoiceDueDate(int $id): void
    {
        $netDays = (int)(AppConfiguration::$ConfigSettings['net_terms_days'] ?? 30);
        if ($netDays < 0)
            $netDays = 0;

        $dueDate = date('Y-m-d', strtotime('+' . $netDays . ' days'));

        $invoiceExists = $this->repository->doesInvoiceExist($id);
        $storedDue = $this->repository->getDueDateFromContractId($id);

        if ($invoiceExists && empty($storedDue)) //Only set due date if there isnt a pre-stored value
            $this->repository->setInvoiceDueDate($id, $dueDate);
    }

    public function denyInvoice(int $id): void
    {
        $this->repository->denyInvoice($id);
    }

    public function getStoredInvoice(int $id): InvoiceData
    {
        $storedInvoice = $this->repository->getStoredInvoice($id);
        return InvoiceData::fromArray($storedInvoice->toArray());
    }

    public function getStoredInvoiceItems(int $id): ItemData
    {
        $storedItems = $this->repository->getStoredInvoiceItems($id);
        return ItemData::fromArray($storedItems->toArray());
    }

    private function validateInvoiceData(InvoiceData $invoiceData): void
    {
        $invoiceData->contract_id ?: null;
        $invoiceData->quote_id ?: null;
        $invoiceData->client_id ?: null;
        $invoiceData->project_id ?: null;
        $invoiceData->discount_type ??= 'none';
        $invoiceData->discount_value ??= 0;
        $invoiceData->tax_percent ??= 0;
        $invoiceData->subtotal ??= 0;
        $invoiceData->total ??= 0;
        $invoiceData->status ??= 'unpaid';
    }

    private function updateAndValidateInvoiceData(InvoiceData $invoiceData): void
    {
        $this->validateInvoiceData($invoiceData);

        $invoiceData->status = 'unpaid';
    }
}
