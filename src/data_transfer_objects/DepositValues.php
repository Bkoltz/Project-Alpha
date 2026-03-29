<?php

namespace App\data_transfer_objects;

interface DepositValues
{
    public function getDepositType(): string;
    public function getDepositAmount(): float;
    public function getTotal(): float;
}

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
