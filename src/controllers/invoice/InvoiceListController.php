<?php

namespace App\controllers\invoice;

use App\controllers\BaseListController;
use App\services\invoice\InvoiceListService;
use App\utils\enum\DocumentType;

class InvoiceListController extends BaseListController
{
    private InvoiceListService $service;

    public function __construct(InvoiceListService $service)
    {
        $this->service = $service;
    }

    public function load(DocumentType $documentType): array
    {
        $filterData = $this->extractFilterData($_GET);
        $countData = $this->extractDisplayCountData($_GET);

        $output = $this->service->getRenderData($documentType, $filterData, $countData);

        return ['pages/invoice/invoice-general-list.twig', $output->toArray()];
    }

    public function markPaid(): void {}
}
