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

    public function toRowsArray() : array {
        $output = [];
        for($i = 0; $i < count($this->item); $i++) {
            $output[] = $this->getRow($i);
        }
        return $output;
    }

    public function validate(): void
    {
        foreach ($this->item as $i => $singleItem) {
            $this->description[$i] = empty($this->description) ? '' : $this->description[$i];
            $this->quantity[$i] = empty($this->quantity) ? 0 : $this->quantity[$i];
            $this->unit_price[$i] = empty($this->unit_price) ? 0 : $this->unit_price[$i];
            $this->line_total[$i] = empty($this->line_total) ? 0 : $this->line_total[$i];
        } 
    }
}
