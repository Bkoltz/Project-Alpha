<?php

namespace App\render_outputs\contract;

use App\render_outputs\RenderOutput;
use App\render_outputs\PageNumberView;

class ContractListView extends RenderOutput
{
    public ?array $rows = null;
    public ?string $title = null;
    public ?array $filter_config = null;
    public ?string $document_type = null;
    public ?PageNumberView $page_number_view = null;
}
