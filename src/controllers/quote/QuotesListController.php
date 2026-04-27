<?php

namespace App\controllers\quote;

use App\controllers\BaseListController;
use App\services\quotes\QuotesListService;
use App\utils\enum\DocumentType;

class QuotesListController extends BaseListController
{
    private QuotesListService $service;

    public function __construct(QuotesListService $service)
    {
        $this->service = $service;
    }

    public function load(DocumentType $documentType = DocumentType::REGULAR) : array {
        $filterData = $this->extractFilterData($_GET);
        $countData = $this->extractDisplayCountData($_GET);

        $output = $this->service->getRenderData($documentType, $filterData, $countData);

        return ['pages/quote/quote-general-list.twig', $output->toArray()];
    }
}
