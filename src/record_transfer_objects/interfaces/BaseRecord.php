<?php

namespace App\record_transfer_objects\interfaces;

interface BaseRecord {
    public function toArray() : array;
    public static function fromArray(array $data) : static;    
}