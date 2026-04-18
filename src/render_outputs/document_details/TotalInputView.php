<?php 

namespace App\render_outputs\document_details;

use App\render_outputs\RenderOutput;

class TotalInputView extends RenderOutput
{
    public ?bool $is_ongoing;
    public ?bool $is_deposit_required;
}