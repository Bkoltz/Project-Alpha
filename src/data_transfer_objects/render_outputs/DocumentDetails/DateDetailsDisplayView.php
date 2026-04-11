<?php

namespace App\data_transfer_objects\render_outputs\DocumentDetails;

use App\data_transfer_objects\render_outputs\RenderOutput;

class DateDetailsDisplayView extends RenderOutput
{
    public ?string $created_at;
    public ?string $document_date;
    public ?string $document_date_updated_at;
}
