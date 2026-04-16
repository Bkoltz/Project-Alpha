<?php

namespace App\record_transfer_objects\invoice\create_record;

use App\data_transfer_objects\TransferObject;
use App\record_transfer_objects\interfaces\InsertableRecord;
use App\record_transfer_objects\interfaces\RetrievableRecord;

class OnDemandInvoiceRecord extends TransferObject implements InsertableRecord, RetrievableRecord
{

    public function toInsertValues(): array
    {
        throw new \Exception('Not implemented');
    }
}
