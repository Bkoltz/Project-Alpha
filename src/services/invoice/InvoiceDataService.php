<?php

namespace App\services\invoice;

use App\render_outputs\invoice\InvoiceCreateView;
use App\render_outputs\RenderOutput;
use App\services\CustomFieldsService;
use App\services\invoice\InvoiceService;
use App\utils\enum\DocumentType;

class InvoiceDataService
{
    private InvoiceService $invoiceService;
    private CustomFieldsService $customFieldService;

    public function __construct(InvoiceService $invoiceService, CustomFieldsService $customFieldService) {
        $this->invoiceService = $invoiceService;
        $this->customFieldService = $customFieldService;
    }

    // TODO make this accept more than just regular documetn type which is hella gay because this is rendered on load and we ened tom ake it dynamic 
    public function getCreateRenderData(): RenderOutput
    {
        $customFields = $this->customFieldService->getCustomFieldInputView(DocumentType::REGULAR);
        $output =  new InvoiceCreateView(['custom_fields' => $customFields]);
        
        return $output;
    }

    public function getEditRenderData(int $id): RenderOutput
    {
        return new RenderOutput();
    }
}
