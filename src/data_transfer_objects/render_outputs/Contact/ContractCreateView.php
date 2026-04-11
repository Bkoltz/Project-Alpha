<?php

namespace App\data_transfer_objects\render_outputs\Contact;

use App\config\AppConfiguration;
use App\data_transfer_objects\render_outputs\DocumentDetails\CustomFieldInputView;
use App\data_transfer_objects\render_outputs\RenderOutput;

class ContractCreateView extends RenderOutput
{
    public ?AppConfiguration $app_config = null;
    public ?CustomFieldInputView $custom_fields = null;
}