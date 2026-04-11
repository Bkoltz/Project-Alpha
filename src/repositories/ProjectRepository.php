<?php

namespace App\repositories;

use App\record_transfer_objects\ProjectDocumentRecord;
use PDO;

class ProjectRepository {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function insertProjectDocuments(ProjectDocumentRecord $record) : void {
        $stmt = $this->pdo->prepare('INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?, ?, ?)');
        $stmt->execute($record->toNumericArray());
    }
}