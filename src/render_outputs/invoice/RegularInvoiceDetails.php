<?php

namespace App\render_outputs\Invoice;

use App\data_transfer_objects\invoice\InvoiceData;
use App\render_outputs\RenderOutput;
use App\render_outputs\document_details\ItemsView;
use App\render_outputs\document_details\CustomFieldDisplayView;
use App\render_outputs\document_details\ContactInfoView;

class RegularInvoiceDetails extends RenderOutput {
    public ?InvoiceData $invoice = null;
    public ?ItemsView $items = null;
    public ?CustomFieldDisplayView $custom_fields = null;
    public ?ContactInfoView $contact_info = null;
}