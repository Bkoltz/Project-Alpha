<?php

namespace APp\data_transfer_objects;

use App\data_transfer_objects\TransferObject;

class ItemData extends TransferObject
{
    public ?array $item = null;
    public ?array $description = null;
    public ?array $quantity = null;
    public ?array $unit_price = null;
    public ?array $line_total = null;
}
