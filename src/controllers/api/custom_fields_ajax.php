<?php
// src/controllers/api/custom_fields_ajax.php
// AJAX endpoint to return custom fields HTML for a given document type

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/document_fields.php';

header('Content-Type: text/html; charset=UTF-8');

$documentType = $_GET['doc_type'] ?? 'regular';
$idSuffix = $_GET['suffix'] ?? '';

// Validate document type
$validTypes = ['regular', 'long_term', 'on_demand'];
if (!in_array($documentType, $validTypes, true)) {
    $documentType = 'regular';
}

// Sanitize suffix (allow only alphanumeric)
$idSuffix = preg_replace('/[^a-zA-Z0-9]/', '', $idSuffix);

// Render and output the custom fields HTML
echo renderDocumentCustomFields($pdo, $documentType, [], $idSuffix);
