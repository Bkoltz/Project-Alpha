<?php

namespace App\render_outputs\document_details;

use App\render_outputs\RenderOutput;

class BrandingView extends RenderOutput
{
    public ?string $name;
    public ?string $logo_path;
}