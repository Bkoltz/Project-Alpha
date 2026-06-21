<?php

namespace App\render_outputs\contract;

use App\data_transfer_objects\contract\ContractData;
use App\data_transfer_objects\contract\ContractSignatures;
use App\render_outputs\document_details\BrandingView;
use App\render_outputs\document_details\ContactInfoView;
use App\render_outputs\RenderOutput;

class LongTermContractDetailsView extends RenderOutput {
    public ?ContractData $contract = null;
    public ?ContactInfoView $contact_info = null;
    public ?ContractSignatures $signatures = null;
    public ?BrandingView $branding = null;
}