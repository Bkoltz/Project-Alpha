<?php

namespace App\data_transfer_objects\render_outputs\Invoice;

use App\data_transfer_objects\InvoiceData;
use App\data_transfer_objects\render_outputs\DocumentDetails\CustomFieldInputView;
use App\data_transfer_objects\render_outputs\DocumentDetails\ItemsView;
use App\data_transfer_objects\TransferObject;

class InvoiceEditView extends TransferObject {
    public ?int $id = null;
    public ?InvoiceData $invoice = null;
    public ?CustomFieldInputView $custom_field = null;
    public ?ItemsView $items = null;
}