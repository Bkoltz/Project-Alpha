<?php

namespace App\record_transfer_objects\contract\create_record;

use App\record_transfer_objects\contract\create_record\BaseContractRecord;

class OnDemandContractRecord extends BaseContractRecord
{
    public ?int $quote_id = null;
    public ?int $client_id = null;
    public ?int $project_id = null;
    public ?string $status = null;
    public ?string $discount_type = null;
    public ?float $discount_value = null;
    public ?float $tax_percent = null;
    public ?float $subtotal = null;
    public ?float $price_per_invoice = null;
    public ?string $deposit_type = null;
    public ?float $deposit_amount = null;
    public ?float $deposit_paid = null;
    public ?string $project_code = null;
    public ?string $start_date = null;
    public ?string $end_date = null;
    public ?int $billing_interval_count = null;
    public ?string $billing_interval_unit = null;
    public ?string $scope = null;

    public function getTotal(): float
    {
        return $this->price_per_invoice;
    }

    public function getDepositAmount(): float
    {
        return $this->deposit_amount;
    }

    public function  getDepositType(): string
    {
        return $this->deposit_type;
    }

    public function toInsertValues(): array
    {
        return $this->toNumericArray();
    }
}
