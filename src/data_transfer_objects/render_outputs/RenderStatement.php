<?php

namespace App\data_transfer_objects\render_outputs;

use App\data_transfer_objects\render_outputs\RenderOutput;
use App\data_transfer_objects\TransferObject;

class RenderStatement extends TransferObject
{
    public ?string $displayPath = null;
    public ?array $output = null;

    public function __construct(string $displayPath, array $output) {
        $this->displayPath = $displayPath;
        $this->output = $output;
    }
}