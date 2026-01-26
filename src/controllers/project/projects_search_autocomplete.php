<?php
// src/controllers/project/projects_search_autocomplete.php
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json');
$term = trim((string)($_GET['term'] ?? ''));
if ($term === '') { echo json_encode([]); exit; }

$sql = "SELECT p.id, p.name, p.client_id, c.name as client_name, p.organization_id, o.name as organization_name
        FROM projects p
        LEFT JOIN clients c ON c.id = p.client_id
        LEFT JOIN organizations o ON o.id = p.organization_id
        WHERE p.name LIKE ?
        ORDER BY p.name
        LIMIT 10";

$stmt = $pdo->prepare($sql);
$stmt->execute(['%'.$term.'%']);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format results for autocomplete
$formatted = array_map(function($row) {
    return [
        'id' => $row['id'],
        'name' => $row['name'],
        'client_name' => $row['client_name'],
        'organization_name' => $row['organization_name']
    ];
}, $results);

echo json_encode($formatted);