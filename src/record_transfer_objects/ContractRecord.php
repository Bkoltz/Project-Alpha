<?php

namespace App\record_transfer_objects;
use App\data_transfer_objects\TransferObject; 

class ContractRecord extends TransferObject {
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

class ContractItemsRecord extends TransferObject {
    public ?array $item = null;
    public ?array $description = null;
    public ?array $quantity = null;
    public ?array $unit_price = null;
    public ?array $line_total = null;

    public function getRow(int $row): array
    {
        return [$this->item[$row], $this->description[$row], $this->quantity[$row], $this->unit_price[$row], $this->line_total[$row]];
    }
}

class ContractMetaRecord extends TransferObject {
    public ?string $project_code = null;
    public ?int $client_id = null;
    public ?string $notes = null;
    public ?string $terms = null;
}