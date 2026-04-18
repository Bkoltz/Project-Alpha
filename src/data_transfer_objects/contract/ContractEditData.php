<?php

namespace App\data_transfer_objects\contract;

use App\data_transfer_objects\TransferObject;

class ContractEditData extends TransferObject
{
    public ?int $client_id = null;
    public ?string $discount_type = null;
    public ?float $discount_value = null;
    public ?float $tax_percent = null;
    public ?float $subtotal = null;
    public ?float $total = null;
    public ?string $terms = null;
    public ?string $estimated_completion = null;
    public ?bool $weather_pending = null;
    public ?string $deposit_type = null;
    public ?float $deposit_amount = null;
    public ?float $deposit_paid = null;
    public ?string $fulfillment_date = null;
    public ?string $scope = null;
    public ?array $custom_fields = null;
}