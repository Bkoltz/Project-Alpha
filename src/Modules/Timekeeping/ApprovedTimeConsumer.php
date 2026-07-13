<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping;

interface ApprovedTimeConsumer
{
    public function consume(array $snapshot): void;

    public function void(array $snapshot): void;
}
