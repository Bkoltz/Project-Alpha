<?php

namespace App\record_transfer_objects\contract\create_record;

use App\data_transfer_objects\TransferObject;
use App\record_transfer_objects\interfaces\InsertableRecord;
use App\record_transfer_objects\interfaces\RetrievableRecord;

abstract class BaseContractRecord extends TransferObject implements RetrievableRecord, InsertableRecord
{
    public function toInsertValues(): array
    {
        return $this->toNumericArray();
    }
}
