<?php 

namespace App\data_transfer_objects\render_outputs\DocumentDetails;

use App\data_transfer_objects\render_outputs\RenderOutput;

class CustomFieldInputView extends RenderOutput
{
    public ?string $id_suffix;
    public ?array $custom_fields;
    public ?string $display_columns;
}
