<?php

namespace App\render_outputs\Invoice;

use App\data_transfer_objects\invoice\InvoiceData;
use App\render_outputs\document_details\CustomFieldInputView;
use App\render_outputs\DocumentDetails\ItemsView;
use App\data_transfer_objects\TransferObject;

class InvoiceEditView extends TransferObject {
    public ?int $id = null;
    public ?InvoiceData $invoice = null;
    public ?CustomFieldInputView $custom_field = null;
    public ?ItemsView $items = null;
}