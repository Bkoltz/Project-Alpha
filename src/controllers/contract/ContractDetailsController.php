<?php

namespace App\controllers\contract;

use App\services\contract\ContractDetailsService;
use App\services\contract\ContractService;
use App\services\SignatureService;
use App\utils\enum\DocumentType;

class ContractDetailsController
{
    private ContractDetailsService $service;
    private ContractService $contractService;
    private SignatureService $signatureService;

    private const RENDER_PATHS = [
        DocumentType::REGULAR->value => 'pages\contract\details\regular-contract-details.twig',
        DocumentType::LONG_TERM->value => 'pages\contract\details\long-term-contract-details.twig',
        DocumentType::ON_DEMAND->value => 'pages\contract\details\on-demand-contract-details.twig'
    ];

    public function __construct(ContractDetailsService $service, ContractService $contractService, SignatureService $signatureService)
    {
        $this->service = $service;
        $this->contractService = $contractService;
        $this->signatureService = $signatureService;
    }

    public function load(DocumentType $documentType = DocumentType::REGULAR): array
    {
        (int)$id = $_GET['id'] ?? 0;
        $output = $this->service->getDetailsRenderData((int)$id, $documentType);
        $path = $this::RENDER_PATHS[$documentType->value];

        return [$path, $output->toArray()];
    }

    public function sign(): void {
        (int)$id = $_POST['id'] ?? 0;
        $documentType = DocumentType::from($_POST['document_type']) ?? DocumentType::REGULAR;
        $file = $_FILES['signed_pdf'];
        $this->signatureService->addSignedDocument($id, $documentType, $file);    
    }
    
    public function pause(DocumentType $documentType = DocumentType::REGULAR): void
    {
        (int)$id = $_POST['id'] ?? 0;
        $this->contractService->pauseContract((int)$id, $documentType);
    }

    public function resume(DocumentType $documentType = DocumentType::REGULAR): void
    {
        (int)$id = $_POST['id'] ?? 0;
        $this->contractService->activateContract((int)$id, $documentType);
    }

    public function activate(DocumentType $documentType = DocumentType::REGULAR): void
    {
        (int)$id = $_POST['id'] ?? 0;
        $this->contractService->activateContract((int)$id, $documentType);
    }

    public function terminate(DocumentType $documentType = DocumentType::REGULAR): void
    {
        (int)$id = $_POST['id'] ?? 0;

        $this->contractService->denyContract((int)$id, $documentType);
    }

    public function complete(DocumentType $documentType = DocumentType::REGULAR): void
    {
        (int)$id = $_POST['id'] ?? 0;
        $this->contractService->completeContract((int)$id, $documentType);
    }

    public function deny(DocumentType $documentType = DocumentType::REGULAR): void
    {
        (int)$id = $_POST['id'] ?? 0;
        $this->contractService->denyContract((int)$id, $documentType);
    }
}
