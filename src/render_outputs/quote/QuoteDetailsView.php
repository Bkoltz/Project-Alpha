<?php

namespace App\render_outputs\quote;

use App\data_transfer_objects\ItemData;
use App\data_transfer_objects\quote\QuoteData;
use App\render_outputs\document_details\BrandingView;
use App\render_outputs\document_details\ContactInfoView;
use App\render_outputs\document_details\CustomFieldDisplayView;
use App\render_outputs\RenderOutput;
use App\utils\enum\DocumentType;

class QuoteDetailsView extends RenderOutput {
    public ?DocumentType $document_type = null; 

    public ?int $id = null;
    public ?QuoteData $quote = null;
    public ?ItemData $items = null;
    public ?array $app_config = null;
    public ?BrandingView $branding = null;
    public ?CustomFieldDisplayView $custom_fields = null;
    public ?ContactInfoView $contact_info = null;
    public ?array $colors = null;
}