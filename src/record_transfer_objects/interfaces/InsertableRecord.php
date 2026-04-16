<?php

namespace App\record_transfer_objects\interfaces;

interface InsertableRecord extends BaseRecord{
    public function toInsertValues() : array;
}