<?php

namespace App\record_transfer_objects;

use App\data_transfer_objects\TransferObject;
use ArrayAccess;

class ItemRecord extends TransferObject
{
    public ?array $item = null;
    public ?array $description = null;
    public ?array $quantity = null;
    public ?array $unit_price = null;
    public ?array $line_total = null;

    public function getRow(int $row): array
    {
        return [$this->item[$row], $this->description[$row], $this->quantity[$row], $this->unit_price[$row], $this->line_total[$row]];
    }

    public static function fromRepoArray(?array $array): ?static
    {
        if ($array == null)
            return null;

        $data = new static();

        $data->item = array_column($array, 'item');
        $data->description = array_column($array, 'description');
        $data->quantity = array_column($array, 'quantity');
        $data->unit_price = array_column($array, 'unit_price');
        $data->line_total = array_column($array, 'line_total');

        return $data;
    }
}
