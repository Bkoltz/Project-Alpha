<?php

namespace App\render_outputs\document_details;

use App\render_outputs\RenderOutput;

class DateDetailsDisplayView extends RenderOutput
{
    public ?string $created_at;
    public ?string $document_date;
    public ?string $document_date_updated_at;
}
