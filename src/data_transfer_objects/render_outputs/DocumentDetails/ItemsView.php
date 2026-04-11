<?php

namespace App\data_transfer_objects\render_outputs\DocumentDetails;

use App\data_transfer_objects\render_outputs\RenderOutput;

class ItemsView extends RenderOutput
{
    public ?string $item;
    public ?string $description;
    public ?int $qunatity;
    public ?float $unit_price;
    public ?float $line_total;
}