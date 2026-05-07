<?php

namespace App\services\contract;

use App\config\AppConfiguration;
use App\render_outputs\contract\ContractEditView;
use App\render_outputs\contract\ContractCreateView;
use App\render_outputs\RenderOutput;
use App\services\CustomFieldsService;
use App\utils\enum\DocumentType;

class ContractDataService
{
    private ContractService $contractService;
    private CustomFieldsService $customFieldService;

    public function __construct(ContractService $contractService, CustomFieldsService $customFieldService)
    {
        $this->contractService = $contractService;
        $this->customFieldService = $customFieldService;
    }
    
    public function getCreateRenderData(): RenderOutput
    {
        $customFields = $this->customFieldService->getCustomFieldInputView(DocumentType::REGULAR);
        
        return new ContractCreateView([
            'custom_fields' => $customFields
        ]);
    }

    public function getEditRenderData(int $id, DocumentType $documentType): RenderOutput
    {
        $contract = $this->contractService->getStoredContract($id, $documentType);
        $items = $this->contractService->getStoredContractItems($id, $documentType);
        $customFields = $this->customFieldService->getCustomFieldInputView($documentType);

        return new ContractEditView([
            'id' => $id,
            'contract' => $contract,
            'items' => $items,
            'custom_fields' => $customFields,
            'app_config' => AppConfiguration::$ConfigSettings
        ]);
    }
}
