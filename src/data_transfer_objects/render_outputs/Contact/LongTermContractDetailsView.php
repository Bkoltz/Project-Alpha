<?php

namespace App\data_transfer_objects\render_outputs\Contact;

use App\data_transfer_objects\ContractData;
use App\data_transfer_objects\render_outputs\RenderOutput;
use App\data_transfer_objects\ContractSignatures;
use App\data_transfer_objects\render_outputs\BrandingView;
use App\data_transfer_objects\render_outputs\ContactInfoView;


class LongTermContractDetailsView extends RenderOutput {
    public ?ContractData $contract = null;
    public ?ContactInfoView $contact_info = null;
    public ?ContractSignatures $signatures = null;
    public ?BrandingView $branding = null;
}