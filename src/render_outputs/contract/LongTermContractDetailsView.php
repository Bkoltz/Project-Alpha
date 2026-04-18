<?php

namespace App\render_outputs\contact;

use App\data_transfer_objects\ContractData;
use App\render_outputs\RenderOutput;
use App\data_transfer_objects\contract\ContractSignatures;
use App\render_outputs\BrandingView;
use App\render_outputs\ContactInfoView;


class LongTermContractDetailsView extends RenderOutput {
    public ?ContractData $contract = null;
    public ?ContactInfoView $contact_info = null;
    public ?ContractSignatures $signatures = null;
    public ?BrandingView $branding = null;
}