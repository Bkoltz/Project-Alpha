<?php 

namespace App\render_outputs\document_details;

use App\render_outputs\RenderOutput;

class CustomFieldInputView extends RenderOutput
{
    public ?string $id_suffix = null;
    public ?array $fields = null;
    public ?int $column_count = 0;
}
