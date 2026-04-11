<?php

namespace App\record_transfer_objects;

use App\data_transfer_objects\TransferObject;

class ProjectDocumentRecord extends TransferObject {
    public ?string $project_id = null;
    public ?string $document_type = null;
    public ?string $document_id = null; 
}