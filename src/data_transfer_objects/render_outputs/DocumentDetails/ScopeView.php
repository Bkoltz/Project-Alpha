<?php

namespace App\data_transfer_objects\render_outputs\DocumentDetails;

use App\data_transfer_objects\render_outputs\RenderOutput;

class ScopeView extends RenderOutput
{
    public ?bool $is_scope_enabled;
    public ?string $scope;
}
