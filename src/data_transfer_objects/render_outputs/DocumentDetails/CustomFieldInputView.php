<?php 

namespace App\data_transfer_objects\render_outputs\DocumentDetails;

use App\data_transfer_objects\render_outputs\RenderOutput;

class CustomFieldInputView extends RenderOutput
{
    public ?string $id_suffix = null;
    public ?array $fields = null;
    public ?int $column_count = 0;
}
