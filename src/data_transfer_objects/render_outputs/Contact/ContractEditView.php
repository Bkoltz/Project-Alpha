<?php

namespace App\data_transfer_objects\render_outputs\Contact;

use App\config\AppConfiguration;
use App\data_transfer_objects\render_outputs\RenderOutput;
use App\data_transfer_objects\ContractEditData;
use App\data_transfer_objects\render_outputs\CustomFieldInputView;

class ContractEditView extends RenderOutput
{
    public ?AppConfiguration $app_config;
    public ?ContractEditData $contract;
    public ?CustomFieldInputView $custom_fields;
}
