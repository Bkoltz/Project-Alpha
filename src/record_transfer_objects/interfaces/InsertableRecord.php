<?php

namespace App\record_transfer_objects\interfaces;

interface InsertableRecord {
    public function toInsertValues() : array;
}