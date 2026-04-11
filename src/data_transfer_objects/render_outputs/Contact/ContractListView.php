<?php

namespace App\data_transfer_objects\render_outputs\Contact;

use App\data_transfer_objects\render_outputs\RenderOutput;
use App\data_transfer_objects\render_outputs\PageNumberView;

class ContractListView extends RenderOutput
{
    public ?array $rows = null;
    public ?string $title = null;
    public ?array $filter_config = null;
    public ?string $document_type = null;
    public ?PageNumberView $page_number_view = null;
}
