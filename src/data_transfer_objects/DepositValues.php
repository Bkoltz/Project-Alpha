<?php

namespace App\data_transfer_objects;

interface DepositValues
{
    public function getDepositType(): string;
    public function getDepositAmount(): float;
    public function getTotal(): float;
}


