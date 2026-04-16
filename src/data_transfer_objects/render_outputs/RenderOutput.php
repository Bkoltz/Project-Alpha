<?php

namespace App\data_transfer_objects\render_outputs;

use App\data_transfer_objects\TransferObject;

abstract class RenderOutput extends TransferObject {
    public function toOutput() : array {
        return $this->toArray();
    }
}