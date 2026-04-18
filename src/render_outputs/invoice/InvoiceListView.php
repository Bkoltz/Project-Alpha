<?php

namespace App\render_outputs\invoice;

use App\render_outputs\PageNumberView;
use App\data_transfer_objects\TransferObject;

class InvoiceListView extends TransferObject
{
    public ?array $rows = null;
    public ?string $title = null;
    public ?array $filter_config = null;
    public ?string $document_type = null;
    public ?PageNumberView $page_number_view = null;
}
