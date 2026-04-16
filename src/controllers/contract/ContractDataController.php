<?php

namespace App\controllers\contract;

use App\data_transfer_objects\ContractData;
use App\data_transfer_objects\ContractEditData;
use App\data_transfer_objects\ContractSignatures;
use APp\data_transfer_objects\ItemData;
use App\services\contract\ContractDataService;
use App\services\contract\ContractService;
use App\services\DocumentService;
use App\utils\enum\DocumentType;

class ContractDataController
{
    private ContractDataService $service;
    private ContractService $contractService;
    private DocumentService $documentService;

    public function __construct(ContractDataService $service, ContractService $contractService, DocumentService $documentService)
    {
        $this->service = $service;
        $this->contractService = $contractService;
        $this->documentService = $documentService;
    }

    public function load(): array
    {
        $output = $this->service->getCreateRenderData();
        error_log(json_encode($output));
        return ['pages\contract\contract-create.twig', $output->toArray()];
    }

    public function createContract()
    {
        $contractData = ContractData::fromArray($_POST);
        $contractSignatures = ContractSignatures::fromArray($_POST);
        $contractItems = ItemData::fromArray($_POST);

        $this->contractService->createContractWithSignatures(DocumentType::REGULAR, $contractData, $contractItems, $contractSignatures);
        $this->documentService->createInvoiceFromContract(DocumentType::REGULAR, $contractData, $contractItems);
    }

    public function updateContract()
    {
        $id = $_POST['id'] ?? 0;

        $contractData = ContractEditData::fromArray($_POST);
        $itemData = ItemData::fromArray($_POST);
        $signatures = ContractSignatures::fromArray($_POST);

        $this->documentService->updateContractAndInvoice($id, $contractData, $itemData, $signatures);
        $this->documentService->updateAllInvoicesItems($id, $itemData);
    }

    public function signContract()
    {
        //Verify upload
        //verify size

        //store file
        //store file path in db
    }
}
