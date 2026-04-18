<?php

namespace App\render_outputs\document_details;

use App\render_outputs\RenderOutput;

class ContactInfoView extends RenderOutput
{
    public ?array $from_lines = null;
    public ?string $from_phone = null;
    public ?string $from_email = null;
    public ?array  $to_lines = null;
    public ?string $to_phone = null;
    public ?string $to_email = null;
}
