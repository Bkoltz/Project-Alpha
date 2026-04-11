<?php

namespace App\data_transfer_objects\render_outputs\DocumentDetails;

use App\data_transfer_objects\render_outputs\RenderOutput;

class BrandingView extends RenderOutput
{
    public ?string $name;
    public ?string $logo_path;
}