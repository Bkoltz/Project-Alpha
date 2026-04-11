<?php

namespace App\record_transfer_objects;

use App\data_transfer_objects\DepositValues;
use App\data_transfer_objects\Traits\GetDepositValues;
use App\data_transfer_objects\TransferObject;

class ContractRecord extends TransferObject implements DepositValues
{
    use GetDepositValues;

    public ?int $quote_id = null;
    public ?int $client_id = null;
    public ?int $project_id = null;
    public ?string $status = null;
    public ?string $discount_type = null;
    public ?float $discount_value = null;
    public ?float $tax_percent = null;
    public ?float $subtotal = null;
    public ?float $total = null;
    public ?string $project_code = null;
    public ?string $deposit_type = null;
    public ?float $deposit_amount = null;
    public ?float $deposit_paid = null;
    public ?string $fulfillment_date = null;
}

class ContractEditRecord extends TransferObject
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

class ContractMetaRecord extends TransferObject
{
    public ?string $project_code = null;
    public ?int $client_id = null;
    public ?string $notes = null;
    public ?string $terms = null;
}

class ContractListRecord extends TransferObject
{
    public ?int $id = null;
    public ?string $doc_number = null;
    public ?string $project_code = null;
    public ?string $status = null;
    public ?float $total = null;
    public ?string $deposit_type = null;
    public ?float $deposit_amount = null;
    public ?float $deposit_paid = null;
    public ?string $signed_pdf_path = null;
    public ?string $client_name = null;
    public ?int $client_id = null;
}
