<?php

namespace App\data_transfer_objects\interfaces;

interface DocumentFinanceGateway
{
    public function getDepositType(): string;
    public function getDepositValue(): float;

    public function getDiscountType(): string;
    public function getDiscountValue(): float;

    public function getSubtotal(): float;
    public function getTotal(): float;
}
