<?php

namespace App\services;

use App\data_transfer_objects\render_outputs\DocumentDetails\CustomFieldDisplayView;
use App\data_transfer_objects\render_outputs\DocumentDetails\CustomFieldInputView;
use App\repositories\CustomFieldsRepository;
use App\utils\enum\DocumentType;

class CustomFieldsService
{
    private CustomFieldsRepository $repositiory;
    
    public function __construct(CustomFieldsRepository $repositiory)
    {
        $this->repositiory = $repositiory;
    }

    public function getCustomFieldInputView(DocumentType $documentType, int $maxColumnDisplay = 3): CustomFieldInputView
    {
        $idSuffix = '';
        $customFields = $this->getCustomFields($documentType);
        $displayColumns =  min(count($customFields), $maxColumnDisplay);

        return new CustomFieldInputView([
            'id_suffix' => $idSuffix,
            'custom_fields' => $customFields,
            'display_columns' => $displayColumns
        ]);
    }

    public function getCustomFieldDisplayView(DocumentType $documentType): CustomFieldDisplayView
    {
        $customFields = $this->getCustomFields($documentType);

        return new CustomFieldDisplayView();
    }

    public function getCustomFields(DocumentType $documentType): array
    {
        return $this->repositiory->getCustomFields($documentType);
    }
}
