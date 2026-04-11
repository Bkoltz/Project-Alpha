<?php

namespace App\data_transfer_objects\Traits;

trait GetDepositValues
{
    public  function getDepositType(): string
    {
        return $this->deposit_type ?? 'none';
    }

    public function getDepositAmount(): float
    {
        return $this->deposit_amount ?? 0;
    }

    public function getTotal(): float
    {
        return $this->total ?? 0;
    }
}
