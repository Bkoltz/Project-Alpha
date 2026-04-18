<?php

namespace App\services\quotes;

use App\config\AppConfiguration;
use App\render_outputs\quote\QuoteCreateView;
use App\render_outputs\quote\QuoteEditView;
use App\services\CustomFieldsService;
use App\utils\enum\DocumentType;

require_once BASE_PATH . '/src/utils/csrf.php';

class QuotesDataService
{
    private QuoteService $quoteService;
    private CustomFieldsService $customFieldService;

    public function __construct(QuoteService $quoteService, CustomFieldsService $customFieldService)
    {
        $this->quoteService = $quoteService;
        $this->customFieldService = $customFieldService;
    }

    public function getCreateRenderData(DocumentType $documentType = DocumentType::REGULAR): QuoteCreateView
    {
        $fields = $this->customFieldService->getCustomFieldInputView($documentType) ?? [];
        $token = csrf_token();

        return new QuoteCreateView(['custom_fields' => $fields]);
    }

    public function getEditRenderData(int $id, DocumentType $documentType = DocumentType::REGULAR): QuoteEditView
    {
        $quote = $this->quoteService->getStoredQuote($id);
        $fields = $this->customFieldService->getCustomFieldDisplayView($documentType) ?? [];

        return new QuoteEditView([
            'quote' => $quote,
            'custom_fields' => $fields,
            'app_config' => AppConfiguration::$ConfigSettings
        ]);
    }
}
