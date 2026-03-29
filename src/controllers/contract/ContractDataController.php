<?php

namespace App\controllers\contract;

use App\data_transfer_objects\ContractData;
use App\data_transfer_objects\ContractSignatures;
use APp\data_transfer_objects\ItemData;
use App\services\contract\ContractService;
use App\services\DocumentService;

class ContractDataController
{
    private ContractService $service;
    private DocumentService $documentService;

    public function __construct(ContractService $service, DocumentService $documentService)
    {
        $this->service = $service;
        $this->documentService = $documentService;
    }

    public function createContract()
    {
        $contractData = ContractData::fromArray($_POST);
        $contractSignatures = ContractSignatures::fromArray($_POST);
        $contractItems = ItemData::fromArray($_POST);

        $this->service->createContractWithSignatures($contractData, $contractItems, $contractSignatures);
        $this->documentService->createInvoiceFromContract($contractData, $contractItems);
    }
}
