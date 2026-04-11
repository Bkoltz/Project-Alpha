<?php

namespace App\data_transfer_objects\render_outputs\Invoice;

use App\data_transfer_objects\InvoiceData;
use App\data_transfer_objects\render_outputs\ContactInfoView;
use App\data_transfer_objects\render_outputs\CustomFieldDisplayView;
use App\data_transfer_objects\render_outputs\ItemsView;
use App\data_transfer_objects\render_outputs\RenderOutput;

class RegularInvoiceDetails extends RenderOutput {
    public ?InvoiceData $invoice = null;
    public ?ItemsView $items = null;
    public ?CustomFieldDisplayView $custom_fields = null;
    public ?ContactInfoView $contact_info = null;
}