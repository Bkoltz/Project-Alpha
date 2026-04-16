<?php

namespace App\record_transfer_objects\contract\create_record;

use App\record_transfer_objects\contract\create_record\BaseContractRecord;

class RegularContractRecord extends BaseContractRecord
{
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

    public function getTotal(): float
    {
        return $this->total;
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
