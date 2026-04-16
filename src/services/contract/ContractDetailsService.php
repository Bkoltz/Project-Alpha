<?php

namespace App\services\contract;

use App\config\AppConfiguration;
use App\data_transfer_objects\render_outputs\Contact\RegularContractDetailsView;
use App\data_transfer_objects\render_outputs\RenderOutput;
use App\services\BaseDetailsService;
use App\services\ClientService;
use App\utils\enum\DocumentType;

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
            'app_config' => new AppConfiguration(),
            'items' => $items,
            'signatures' => $signatures,
            'contract_info' => $contactInfo,
            'branding' => $branding
        ]);
    }

    private function getLongTermDetails(int $id): RenderOutput
    {
        $contract = $this->contractService->getStoredContract($id, DocumentType::LONG_TERM);
        $signatures = $this->contractService->getStoredSignatures($id);
        $branding = $this->getBranding();
        $contactInfo = $this->getContactInfo($contract->client_id);

        return new RegularContractDetailsView([
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
