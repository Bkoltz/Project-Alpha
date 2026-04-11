<?php

namespace App\repositories\meta;

use App\record_transfer_objects\MetaRecord;
use PDO;

class MetaRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function setMeta(MetaRecord $metaRecord): void
    {
        echo json_encode($metaRecord);
        $up = $this->pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes, terms) VALUES (:project_code, :client_id, :notes, :terms) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes), terms=VALUES(terms)');
        $up->execute($metaRecord->toArray());
    }
}
