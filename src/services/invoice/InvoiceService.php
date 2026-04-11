<?php

namespace App\services\invoice;

use App\data_transfer_objects\InvoiceData;
use APp\data_transfer_objects\ItemData;
use App\record_transfer_objects\InvoiceRecord;
use App\record_transfer_objects\ItemRecord;
use App\repositories\invoice\InvoiceRepository;
use App\data_transfer_objects\InvoiceEditData;

use App\config\AppConfiguration;
use App\record_transfer_objects\InvoiceEditRecord;
use App\record_transfer_objects\MetaRecord;
use App\services\MetaService;
use App\services\ProjectService;

class InvoiceService
{
    private InvoiceRepository $repository;
    private MetaService $metaService;
    private ProjectService $projectService;

    public function __construct(InvoiceRepository $repository, MetaService $metaService)
    {
        $this->repository = $repository;
        $this->metaService = $metaService;
    }

    public function createInvoice(InvoiceData $invoiceData, ItemData $invoiceItems)
    {
        $this->updateAndValidateInvoiceData($invoiceData);
        $this->validateInvoiceItems($invoiceItems);

        $record = InvoiceRecord::fromArray($invoiceData->toArray());
        $itemsRecord = ItemRecord::fromArray($invoiceItems->toArray());

        $this->repository->createInvoice($record, $itemsRecord);
        $this->metaService->setProjectMeta(new MetaRecord()); // TODO fix this later
        $this->projectService->insertInvoiceProjectDoc(0,0); //TODO this to
    }

    public function updateInvoice(int $id, InvoiceEditData $invoiceData, ItemData $invoiceItems): void
    {
        $record = InvoiceEditRecord::fromArray($invoiceData->toArray());
        $recordItems = ItemRecord::fromArray($invoiceItems->toArray());

        $this->repository->updateFullInvoice($id, $record, $recordItems);
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
        $this->repository->voidInvoice($id);
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

    public function getStoredInvoice(int $id) : InvoiceData {
        $storedInvoice = $this->repository->getStoredInvoice($id);
        return InvoiceData::fromArray($storedInvoice->toArray());
    }

    public function getStoredInvoiceItems(int $id) : ItemData {
        $storedItems = $this->repository->getStoredInvoiceItems($id);
        return ItemData::fromArray($storedItems->toArray());
    }

    private function validateInvoiceItems(ItemData $invoiceItems): void
    {
        $invoiceItems->item ??= [];
        $invoiceItems->description ??= [];
        $invoiceItems->quantity ??= [];
        $invoiceItems->unit_price ??= [];
    }

    // contract_id, quote_id, client_id, project_id, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, project_code, fulfillment_date
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
