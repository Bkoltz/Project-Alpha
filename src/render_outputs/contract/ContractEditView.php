<?php

namespace App\render_outputs\contract;

use App\config\AppConfiguration;
use App\render_outputs\RenderOutput;
use App\data_transfer_objects\contract\ContractData;
use App\data_transfer_objects\ItemData;
use App\render_outputs\document_details\CustomFieldInputView;

class ContractEditView extends RenderOutput
{
    public ?int $id = null;
    public ?ContractData $contract = null;
    public ?ItemData $items = null;
    public ?CustomFieldInputView $custom_fields = null;
    public ?array $app_config = null;
}
