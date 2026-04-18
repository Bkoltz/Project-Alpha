<?php

namespace App\render_outputs\quote;

use App\config\AppConfiguration;
use App\data_transfer_objects\quote\QuoteData;
use App\render_outputs\document_details\CustomFieldInputView;
use App\data_transfer_objects\TransferObject;

class QuoteEditView extends TransferObject {
    public ?QuoteData $quote = null;
    public ?array $app_config = null;
    public ?CustomFieldInputView $custom_fields = null;
}