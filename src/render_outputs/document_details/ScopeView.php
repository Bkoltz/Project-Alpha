<?php

namespace App\render_outputs\document_details;

use App\render_outputs\RenderOutput;

class ScopeView extends RenderOutput
{
    public ?bool $is_scope_enabled;
    public ?string $scope;
}
