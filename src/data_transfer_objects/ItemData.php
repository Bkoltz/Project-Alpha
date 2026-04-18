<?php

namespace App\data_transfer_objects;

use App\data_transfer_objects\TransferObject;

class ItemData extends TransferObject
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

    public function validate(): void
    {
        if ($this == null)
            return; 

        $this->item ??= [];
        $this->description ??= [];
        $this->quantity ??= [];
        $this->unit_price ??= [];
        $this->line_total ??= [];
    }

    public function isNull(): bool
    {
        if (empty($item))
            return true;
        
        $index = 0;
        foreach ($this->item as $item) {
            if (empty($item))
                $index++;
        }

        return $index == count($this->item);
    }
}
