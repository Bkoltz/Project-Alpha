<?php

namespace App\record_transfer_objects\contract\create_record;

use App\data_transfer_objects\TransferObject;
use App\data_transfer_objects\DepositValues;
use App\record_transfer_objects\interfaces\InsertableRecord;
use App\record_transfer_objects\interfaces\RetrievableRecord;

abstract class BaseContractRecord extends TransferObject implements RetrievableRecord, InsertableRecord, DepositValues {
    public abstract function getDepositType(): string;
    public abstract function getDepositAmount(): float;
    public abstract function getTotal(): float;
}