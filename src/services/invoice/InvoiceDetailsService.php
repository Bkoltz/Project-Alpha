<?php

namespace App\services\invoice;

use App\data_transfer_objects\render_outputs\Invoice\LongTermInvoiceDetailsView;
use App\data_transfer_objects\render_outputs\Invoice\OnDemandInvoiceDetails;
use App\data_transfer_objects\render_outputs\Invoice\RegularInvoiceDetails;
use App\data_transfer_objects\render_outputs\RenderOutput;
use App\services\BaseDetailsService;
use App\services\ClientService;
use App\utils\enum\DocumentType;

class InvoiceDetailsService extends BaseDetailsService
{
    private InvoiceService $invoiceService;

    public function __construct(InvoiceService $invoiceService, ClientService $clientService)
    {
        parent::__construct($clientService);

        $this->invoiceService = $invoiceService;
    }

    public function getDetailsRenderData(int $id, DocumentType $documentType): RenderOutput
    {
        return match ($documentType) {
            DocumentType::LONG_TERM => $this->getLongTermDetails($id),
            DocumentType::ON_DEMAND => $this->getOnDemandDetails($id),
            default => $this->getRegularDetails($id)
        };
    }

    private function getRegularDetails(int $id): RegularInvoiceDetails
    {
        $invoice = $this->invoiceService->getStoredInvoice($id);
        $items = $this->invoiceService->getStoredInvoiceItems($id);
        $contactInfo = $this->getContactInfo($invoice->client_id);

        return new RegularInvoiceDetails(array_merge([
            'invoice' => $invoice,
            'items' => $items,
            'contact_info' => $contactInfo
        ]));
    }

    private function getLongTermDetails(int $id): LongTermInvoiceDetailsView
    {
        return new LongTermInvoiceDetailsView();
    }

    private function getOnDemandDetails(int $id): OnDemandInvoiceDetails
    {
        return new OnDemandInvoiceDetails();
    }
}
