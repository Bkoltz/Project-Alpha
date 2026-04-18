<?php

namespace App\services\contract;

use App\config\AppConfiguration;
use App\render_outputs\Contact\LongTermContractDetailsView;
use App\render_outputs\contract\RegularContractDetailsView;
use App\services\BaseDetailsService;
use App\services\ClientService;
use App\utils\enum\DocumentType;
use App\render_outputs\RenderOutput;

class ContractDetailsService extends BaseDetailsService
{
    private ContractService $contractService;

    public function __construct(ContractService $contractService, ClientService $clientService)
    {
        parent::__construct($clientService);

        $this->contractService = $contractService;
    }

    public function getDetailsRenderData(int $id, DocumentType $documentType): RenderOutput
    {
          return match ($documentType) {
            DocumentType::REGULAR => $this->getRegularDetails($id),
            DocumentType::LONG_TERM => $this->getLongTermDetails($id),
            DocumentType::ON_DEMAND => $this->getOnDemandDetails($id),
        };
    }

    private function getRegularDetails(int $id): RenderOutput
    {
        $contract = $this->contractService->getStoredContract($id, DocumentType::REGULAR);
        $signatures = $this->contractService->getStoredSignatures($id);
        $items = $this->contractService->getStoredContractItems($id);
        $branding = $this->getBranding();
        $contactInfo = $this->getContactInfo($contract->client_id);

        return new RegularContractDetailsView([
            'contract' => $contract,
            'app_config' => AppConfiguration::$ConfigSettings,
            'items' => $items,
            'signatures' => $signatures,
            'contact_info' => $contactInfo,
            'branding' => $branding
        ]);
    }

    private function getLongTermDetails(int $id): RenderOutput
    {
        $contract = $this->contractService->getStoredContract($id, DocumentType::LONG_TERM);
        $signatures = $this->contractService->getStoredSignatures($id);
        $branding = $this->getBranding();
        $contactInfo = $this->getContactInfo($contract->client_id);

        return new LongTermContractDetailsView([
            'contract' => $contract,
            'signatures' => $signatures,
            'contract_info' => $contactInfo,
            'branding' => $branding
        ]);
    }

    private function getOnDemandDetails(int $id): RenderOutput
    {
        return new RenderOutput();
    }

}
