<?php

namespace App\data_transfer_objects\render_outputs\DocumentDetails;

use App\data_transfer_objects\render_outputs\RenderOutput;


class TotalDetailsDisplayView extends RenderOutput
{
    public ?string $discount_type;
    public ?float $discount_value;
    public ?float $tax_percent;
    public ?float $total;
    public ?float $subtotal;
}