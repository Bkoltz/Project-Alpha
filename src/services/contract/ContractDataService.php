<?php

namespace App\services\contract;

use App\data_transfer_objects\render_outputs\Contact\ContractCreateView;
use App\data_transfer_objects\render_outputs\RenderOutput;
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
        return new ContractCreateView(['custom_field' => $customFields]);
    }

    public function getEditRenderData(int $id): RenderOutput
    {
        return new RenderOutput();
    }
}
