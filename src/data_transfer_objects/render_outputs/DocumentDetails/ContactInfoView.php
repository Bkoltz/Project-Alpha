<?php

namespace App\data_transfer_objects\render_outputs\DocumentDetails;

use App\data_transfer_objects\render_outputs\RenderOutput;

class ContactInfoView extends RenderOutput
{
    public ?array $from_lines;
    public ?string $from_phone;
    public ?string $from_email;
    public ?array  $to_lines;
    public ?string $to_phone;
    public ?string $to_email;
}
