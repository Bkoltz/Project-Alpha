<?php

namespace App\data_transfer_objects\render_outputs\DocumentDetails;

use App\data_transfer_objects\render_outputs\RenderOutput;

class CustomFieldDisplayView extends RenderOutput
{
    public ?bool $show_deposit_info;
    public ?float $deposit_due;
    public ?bool $show_fulfillment_date;
    public ?string $fulfillment_date;
    public ?array $custom_fields;
}
