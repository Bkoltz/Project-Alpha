<?php

namespace App\services\invoice;

use App\data_transfer_objects\InvoiceData;
use APp\data_transfer_objects\ItemData;
use App\record_transfer_objects\InvoiceRecord;
use App\record_transfer_objects\ItemRecord;
use App\repositories\invoice\InvoicesRepository;

use App\config\AppConfiguration;

class InvoiceService
{
    private InvoicesRepository $repository;

    public function __construct(InvoicesRepository $repository)
    {
        $this->repository = $repository;
    }

    public function createInvoice(InvoiceData $invoiceData, ItemData $invoiceItems)
    {
        $this->validateInvoiceData($invoiceData);
        $this->validateInvoiceItems($invoiceItems);

        $record = InvoiceRecord::fromArray($invoiceData->toArray());
        $itemsRecord = ItemRecord::fromArray($invoiceItems->toArray());

        $this->repository->createInvoice($record, $itemsRecord);
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

    public function denyInvoice(int $id) : void {
        $this->repository->denyInvoice($id);
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
        $invoiceData->contract_id ??= 0;
        $invoiceData->quote_id ??= 0;
        $invoiceData->client_id ??= 0;
        $invoiceData->project_id ??= 0;
        $invoiceData->discount_type ??= 'none';
        $invoiceData->discount_value ??= 0;
        $invoiceData->tax_percent ??= 0;
        $invoiceData->subtotal ??= 0;
        $invoiceData->total ??= 0;
        $invoiceData->status ??= 'unpaid';
    }
}
