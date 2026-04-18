<?php

namespace App\render_outputs\Invoice;

use App\render_outputs\document_details\CustomFieldInputView;
use App\render_outputs\RenderOutput;

class InvoiceCreateView extends RenderOutput {
    public ?CustomFieldInputView $custom_fields = null;
}