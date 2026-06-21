<?php

namespace App\record_transfer_objects\invoice\create_record;

use App\data_transfer_objects\TransferObject;
use App\record_transfer_objects\interfaces\InsertableRecord;
use App\record_transfer_objects\interfaces\RetrievableRecord;

abstract class BaseInvoiceRecord extends TransferObject implements InsertableRecord, RetrievableRecord {
      public function toInsertValues(): array
    {
        return $this->toArray();
    }
}