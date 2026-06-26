<?php
// src/controllers/api/quotes_list.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
header('Content-Type: application/json');

$limit = (int)($_GET['limit'] ?? 10);
if ($limit < 1 || $limit > 100) $limit = 10;
$status = $_GET['status'] ?? '';

$where = [];
$params = [];
if ($status !== '') {
    $where[] = 'q.status=?';
    $params[] = $status;
}
[$scopeWhere, $scopeParams] = scope_clause($pdo, 'q', (int)$_SESSION['user']['id']);
if ($scopeWhere !== '') {
    $where[] = trim($scopeWhere);
    $params = array_merge($params, $scopeParams);
}
$whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT q.id, q.doc_number, q.client_id, q.total, q.status, q.created_at, q.updated_at FROM quotes q {$whereSQL} ORDER BY q.updated_at DESC LIMIT ?";
$params[] = $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_NUMERIC_CHECK);
