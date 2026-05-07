<?php

namespace App\controllers\contract;

use App\data_transfer_objects\contract\ContractSignatures;
use App\data_transfer_objects\contract\ContractData;
use App\data_transfer_objects\ItemData;
use App\services\contract\ContractDataService;
use App\services\contract\ContractService;
use App\services\DocumentService;
use App\utils\enum\DocumentType;

class ContractDataController
{
    private ContractDataService $service;
    private ContractService $contractService;
    private DocumentService $documentService;

    private const CONTRACT_EDIT_PATH = [
        DocumentType::REGULAR->value => 'pages\contract\edit\regular-contract-edit.twig',
        DocumentType::LONG_TERM->value => 'pages\contract\edit\long-term-contract-edit.twig',
        DocumentType::ON_DEMAND->value => 'pages\contract\edit\on-demand-contract-edit.twig',
    ];

    public function __construct(ContractDataService $service, ContractService $contractService, DocumentService $documentService)
    {
        $this->service = $service;
        $this->contractService = $contractService;
        $this->documentService = $documentService;
    }

    public function load(DocumentType $documentType = DocumentType::REGULAR): array
    {
        $output = null;

        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            $output = $this->service->getEditRenderData($id, $documentType);
            $path = self::CONTRACT_EDIT_PATH[$documentType->value];

            return[$path, $output->toArray()];
        } else {
            $output = $this->service->getCreateRenderData();

            return ['pages\contract\contract-create.twig', $output->toArray()];
        }
        
    }

    public function create(DocumentType $documentType = DocumentType::REGULAR)
    {
        $contractData = ContractData::fromArray($_POST);
        $contractSignatures = ContractSignatures::fromArray($_POST);
        $contractItems = ItemData::fromArray($_POST);

        $this->contractService->createContractWithSignatures($documentType, $contractData, $contractItems, $contractSignatures);
        $this->documentService->createInvoiceFromContract($documentType, $contractData, $contractItems);
    }

    public function update(DocumentType $documentType)
    {
        $id = (int)$_POST['id'] ?? 0;

        $contractData = ContractData::fromArray($_POST);
        $itemData = ItemData::fromArray($_POST);
        $signatures = ContractSignatures::fromArray($_POST);

        $this->contractService->updateContract($id, $documentType, $contractData, $itemData);
        // $this->documentService->updateContractAndInvoice($id, $documentType, $contractData, $itemData, $signatures);
        // $this->documentService->updateAllInvoicesItems($id, $itemData);
    }

    public function signContract()
    {
        //Verify upload
        //verify size

        //store file
        //store file path in db
    }
}
