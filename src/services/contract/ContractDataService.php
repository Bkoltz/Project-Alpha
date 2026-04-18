<?php

namespace App\services\contract;

use App\render_outputs\contract\ContractCreateView;
use App\render_outputs\RenderOutput;
use App\services\CustomFieldsService;
use App\utils\enum\DocumentType;

class ContractDataService
{
    private CustomFieldsService $customFieldService;

    public function __construct(CustomFieldsService $customFieldService)
    {
        $this->customFieldService = $customFieldService;
    }
    
    public function getCreateRenderData(): RenderOutput
    {
        $customFields = $this->customFieldService->getCustomFieldInputView(DocumentType::REGULAR);
        return new ContractCreateView(['custom_fields' => $customFields]);
    }

    public function getEditRenderData(int $id): RenderOutput
    {
        return new RenderOutput();
    }
}
