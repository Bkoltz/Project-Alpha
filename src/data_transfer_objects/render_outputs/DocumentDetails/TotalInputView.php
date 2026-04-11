<?php 

namespace App\data_transfer_objects\render_outputs\DocumentDetails;

use App\data_transfer_objects\render_outputs\RenderOutput;

class TotalInputView extends RenderOutput
{
    public ?bool $is_ongoing;
    public ?bool $is_deposit_required;
}