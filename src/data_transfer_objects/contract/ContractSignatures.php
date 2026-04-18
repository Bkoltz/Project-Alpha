<?php

namespace App\data_transfer_objects\contract;

use App\data_transfer_objects\TransferObject;

class ContractSignatures extends TransferObject
{
    public ?array $signature_titles = null;
    public ?array $signature_orders = null;
    public ?array $signature_required = null;

    public function getRow(int $row): array
    {
        return [$this->signature_titles[$row], $this->signature_orders[$row], $this->signature_required[$row]];
    }
}