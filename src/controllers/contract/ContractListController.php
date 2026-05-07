<?php

namespace App\controllers\contract;

use App\controllers\BaseListController;
use App\services\contract\ContractListService;
use App\utils\enum\DocumentType;

class ContractListController extends BaseListController
{
    private ContractListService $service;

    public function __construct(ContractListService $service)
    {
        $this->service = $service;
    }

    public function load(DocumentType $documentType)
    {
        $filterData = $this->extractFilterData($_GET);
        $countData = $this->extractDisplayCountData($_GET);

        $output = $this->service->getRenderData($documentType, $filterData, $countData);

        return ['pages/contract/contracts-general-list.twig', $output->toArray()];
    }
}
