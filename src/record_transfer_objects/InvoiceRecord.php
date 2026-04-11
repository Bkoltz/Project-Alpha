<?php

namespace App\record_transfer_objects;

use App\data_transfer_objects\TransferObject;

class InvoiceRecord extends TransferObject
{
    public ?int $contract_id = null;
    public ?int $quote_id = null;
    public ?int $client_id = null;
    public ?int $project_id = null;
    public ?string $discount_type = null;
    public ?float $discount_value = null;
    public ?float $tax_percent = null;
    public ?float $subtotal = null;
    public ?float $total = null;
    public ?string $status = null;
    public ?string $due_date = null;
    public ?string $project_code = null;
    public ?string $fulfillment_date = null;
}

class InvoiceEditRecord extends TransferObject
{
    public ?int $client_id = null;
    public ?string $discount_type = null;
    public ?float $discount_value = null;
    public ?float $tax_percent = null;
    public ?float $subtotal = null;
    public ?float $total = null;
    public ?string $due_date = null;
    public ?string $fulfillment_date = null;
    public ?bool $weather = null;
    public ?string $scope = null;
}
