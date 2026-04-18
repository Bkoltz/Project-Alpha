<?php

namespace App\render_outputs\document_details;

use App\render_outputs\RenderOutput;

class ItemsView extends RenderOutput
{
    public ?string $item;
    public ?string $description;
    public ?int $qunatity;
    public ?float $unit_price;
    public ?float $line_total;
}