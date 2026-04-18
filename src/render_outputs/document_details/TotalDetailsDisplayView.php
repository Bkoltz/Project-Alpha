<?php

namespace App\render_outputs\document_details;

use App\render_outputs\RenderOutput;


class TotalDetailsDisplayView extends RenderOutput
{
    public ?string $discount_type;
    public ?float $discount_value;
    public ?float $tax_percent;
    public ?float $total;
    public ?float $subtotal;
}