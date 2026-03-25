<?php

namespace App\services\invoice;

use App\data_transfer_objects\InvoiceData;
use App\repositories\invoice\InvoicesRepository;

class InvoiceService
{
    private InvoicesRepository $repository;

    public function __construct(InvoicesRepository $repository)
    {
        $this->repository = $repository;
    }

    public function createInvoice(InvoiceData $invoiceData) {
        $sortedData = $this->sortInvoiceData($invoiceData);
        $sortedItemData = $this->sortInvoiceItems($invoiceData);

        $this->repository->createInvoice($sortedData, $sortedItemData);
    }

    private function sortInvoiceItems(InvoiceData $invoiceData): array {
        $items = $invoiceData->toArray()['items'];

        return [
            $items['item'],
            $items['desc'],
            $items['qty'],
            $items['price']
        ];
    }

    // contract_id, quote_id, client_id, project_id, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, project_code, fulfillment_date
    private function sortInvoiceData(InvoiceData $invoiceData): array
    {
        $invoiceData = $invoiceData->toArray();

        return [
            $invoiceData['contract_id'],
            $invoiceData['quote_id'],
            $invoiceData['client_id'],
            $invoiceData['project_id'],
            $invoiceData['discount_type'],
            $invoiceData['discount_value'],
            $invoiceData['tax_percent'],
            $invoiceData['subtotal'],
            $invoiceData['total'],
            'unpaid',
            $invoiceData['due_date'],
            $invoiceData['project_code'],
            $invoiceData['fulfillment_date'],
        ];
    }
}
