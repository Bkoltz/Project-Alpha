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
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            $output = $this->service->getEditRenderData($id, $documentType);
            $path = self::CONTRACT_EDIT_PATH[$documentType->value];

            return [$path, $output->toArray()];
        } else {
            $output = $this->service->getCreateRenderData();

            return ['pages\contract\contract-create.twig', $output->toArray()];
        }
    }

    public function create()
    {
        $postData = $this->resolveDateFields($_POST);

        $contractData = ContractData::fromArray($postData);
        $contractSignatures = ContractSignatures::fromArray($postData);
        $contractItems = ItemData::fromArray($postData);

        $documentType = DocumentType::from($postData['document_type']);

        $this->contractService->createContractWithSignatures($documentType, $contractData, $contractItems, $contractSignatures);
        $this->documentService->createInvoiceFromContract($documentType, $contractData, $contractItems);
    }

    public function update(DocumentType $documentType)
    {
        $id = (int)$_POST['id'] ?? 0;
        $postData = $this->resolveDateFields($_POST);

        $contractData = ContractData::fromArray($postData);
        $itemData = ItemData::fromArray($postData);
        $signatures = ContractSignatures::fromArray($postData);

        $this->contractService->updateContract($id, $documentType, $contractData, $itemData);
        // $this->documentService->updateContractAndInvoice($id, $documentType, $contractData, $itemData, $signatures);
        // $this->documentService->updateAllInvoicesItems($id, $itemData);
    }

    private function resolveDateFields(array $postData): array
    {
        $postData['start_date'] = match ($postData['document_type'] ?? null) {
            'long_term' => $postData['long_term_start_date'] ?? null,
            'on_demand' => $postData['on_demand_start_date'] ?? null,
            default => $postData['start_date'] ?? null
        };

        $postData['end_date'] = match ($postData['document_type'] ?? null) {
            'long_term' => $postData['long_term_end_date'] ?? null,
            'on_demand' => $postData['on_demand_end_date'] ?? null,
            default => $postData['end_date'] ?? null
        };

        return $postData;
    }
}
