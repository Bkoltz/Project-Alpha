<?php
// src/controllers/organization/org_search.php
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');
$term = isset($_GET['term']) ? trim((string)$_GET['term']) : '';
if ($term === '') {
    echo json_encode([]);
    exit;
}

try {
    $st = $pdo->prepare('SELECT id, name FROM organizations WHERE name LIKE ? ORDER BY name ASC LIMIT 15');
    $like = '%' . str_replace('%','\\%',$term) . '%';
    $st->execute([$like]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(array_values($rows));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([]);
}
