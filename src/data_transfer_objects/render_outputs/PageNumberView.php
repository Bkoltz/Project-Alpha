<?php

namespace App\data_transfer_objects\render_outputs;

use App\data_transfer_objects\TransferObject;

class PageNumberView extends TransferObject
{
    public ?int $display_count = null;
    public ?string $next_page_path = null;
    public ?string $previous_page_path = null;
    public ?int $page_count = null;
    public ?int $per_page = null;
    public ?int $page = null;
    public ?int $offset = null;
}
