<?php

namespace App\data_transfer_objects\render_outputs\Invoice;

use App\data_transfer_objects\render_outputs\DocumentDetails\CustomFieldInputView;
use App\data_transfer_objects\render_outputs\RenderOutput;

class InvoiceCreateView extends RenderOutput {
    public ?CustomFieldInputView $custom_field = null;
}