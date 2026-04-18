<?php

namespace App\render_outputs\contract;

use App\config\AppConfiguration;
use App\render_outputs\document_details\CustomFieldInputView;
use App\render_outputs\RenderOutput;

class ContractCreateView extends RenderOutput
{
    public ?AppConfiguration $app_config = null;
    public ?CustomFieldInputView $custom_fields = null;
}