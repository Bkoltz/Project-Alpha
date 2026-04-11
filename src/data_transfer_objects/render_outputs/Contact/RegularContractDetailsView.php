<?php

namespace App\data_transfer_objects\render_outputs\Contact;

use App\config\AppConfiguration;
use App\data_transfer_objects\ContractData;
use App\data_transfer_objects\render_outputs\RenderOutput;
use App\data_transfer_objects\ContractSignatures;
use App\data_transfer_objects\ItemData;
use App\data_transfer_objects\render_outputs\DocumentDetails\BrandingView;
use App\data_transfer_objects\render_outputs\DocumentDetails\ContactInfoView;
use App\data_transfer_objects\render_outputs\DocumentDetails\CustomFieldDisplayView;

class RegularContractDetailsView extends RenderOutput {
    public ?ContractData $contract = null;
    public ?AppConfiguration $app_config = null;
    public ?CustomFieldDisplayView $custom_fields = null;
    public ?ContactInfoView $contact_info = null;
    public ?ContractSignatures $signatures = null;
    public ?BrandingView $branding = null;
    public ?ItemData $items = null;
}