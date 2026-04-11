<?php

namespace App\record_transfer_objects;

use App\data_transfer_objects\TransferObject;

class MetaRecord extends TransferObject
{
    public ?string $project_code = null;
    public ?int $client_id = null;
    public ?string $notes = null;
    public ?string $terms = null;
}