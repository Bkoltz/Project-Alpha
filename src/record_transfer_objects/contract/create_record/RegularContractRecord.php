<?php

namespace App\record_transfer_objects\contract\create_record;

use App\record_transfer_objects\contract\create_record\BaseContractRecord;
use Override;

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
    public ?float $deposit_value = null;
    public ?float $deposit_paid = null;
    public ?string $fulfillment_date = null;
    public ?string $created_at = null;
    public ?string $signed_pdf_path = null;


    public function toInsertValues(): array
    {
        return [
            $this->quote_id,
            $this->client_id,
            $this->project_id,
            $this->status,
            $this->discount_type,
            $this->discount_value,
            $this->tax_percent,
            $this->subtotal,
            $this->total,
            $this->project_code,
            $this->deposit_type,
            $this->deposit_value,
            $this->deposit_paid,
            $this->fulfillment_date,
            $this->created_at,
        ];
    }
}
