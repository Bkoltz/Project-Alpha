<?php

use App\data_transfer_objects\render_outputs\PageNumberView;
use App\data_transfer_objects\render_outputs\RenderOutput;

class ListRenderOutput extends RenderOutput
{
    public ?array $rows = null;
    public ?string $title = null;
    public ?array $filter_config = null;
    public ?string $document_type = null;
    public ?PageNumberView $page_number_control = null;
}
