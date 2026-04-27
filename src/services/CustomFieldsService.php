<?php

namespace App\services;

use App\render_outputs\document_details\CustomFieldDisplayView;
use App\render_outputs\document_details\CustomFieldInputView;
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

        $this->decodeJsonOptions($customFields);

        return new CustomFieldInputView([
            'id_suffix' => $idSuffix,
            'fields' => $customFields,
            'column_count' => $displayColumns
        ]);
    }

    // It is important to note that this is using a direct reference, so dont fuck it up lmao
    private function decodeJsonOptions(array &$customFields): void
    {
        foreach ($customFields as &$field) {
            if (isset($field['field_options']))
                $field['field_options'] = json_decode($field['field_options'], true);
        }
    }

    public function getCustomFieldDisplayView(DocumentType $documentType): CustomFieldDisplayView
    {
        $customFields = $this->getCustomFields($documentType);

        return new CustomFieldDisplayView(['custom_fields' => $customFields]);
    }

    public function getCustomFields(DocumentType $documentType): array
    {
        return $this->repositiory->getCustomFields($documentType);
    }
}
