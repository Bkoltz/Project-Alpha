<?php
// src/controllers/api/quotes_list.php
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json');

$limit = (int)($_GET['limit'] ?? 10);
if ($limit < 1 || $limit > 100) $limit = 10;
$status = $_GET['status'] ?? '';

$sql = "SELECT id, doc_number, client_id, total, status, created_at, updated_at FROM quotes";
$params = [];
if ($status !== '') {
    $sql .= " WHERE status=?";
    $params[] = $status;
}
$sql .= " ORDER BY updated_at DESC LIMIT ?";
$params[] = $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_NUMERIC_CHECK);
