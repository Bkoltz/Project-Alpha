<?php

namespace App\render_outputs\contract;

use App\data_transfer_objects\contract\ContractData;
use App\render_outputs\RenderOutput;
use App\render_outputs\document_details\CustomFieldDisplayView;
use App\render_outputs\document_details\ContactInfoView;
use App\render_outputs\document_details\BrandingView;
use App\data_transfer_objects\ItemData;
use App\data_transfer_objects\contract\ContractSignatures;

class RegularContractDetailsView extends RenderOutput {
    public ?ContractData $contract = null;
    public ?array $app_config = null;
    public ?CustomFieldDisplayView $custom_fields = null;
    public ?ContactInfoView $contact_info = null;
    public ?ContractSignatures $signatures = null;
    public ?BrandingView $branding = null;
    public ?ItemData $items = null;
}