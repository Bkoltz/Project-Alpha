<?php

namespace App\repositories;

use App\utils\enum\DocumentType;
use PDO;

class CustomFieldsRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getCustomFields(DocumentType $documentType): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM document_custom_fields WHERE document_type = ? AND is_enabled = 1 ORDER BY display_order, id');
        $stmt->execute([$documentType->value]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
