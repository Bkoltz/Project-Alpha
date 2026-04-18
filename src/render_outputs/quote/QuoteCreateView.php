<?php 

namespace App\render_outputs\quote;

use App\render_outputs\document_details\CustomFieldInputView;

use App\render_outputs\RenderOutput;

class QuoteCreateView extends RenderOutput {
    public ?CustomFieldInputView $custom_fields = null;
}