<?php

namespace App\render_outputs\contact;

use App\config\AppConfiguration;
use App\render_outputs\RenderOutput;
use App\data_transfer_objects\contract\ContractEditData;
use App\render_outputs\document_details\CustomFieldInputView;

class ContractEditView extends RenderOutput
{
    public ?AppConfiguration $app_config = null;
    public ?ContractEditData $contract = null;
    public ?CustomFieldInputView $custom_fields = null;
}
