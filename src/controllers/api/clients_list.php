<?php
// src/controllers/api/clients_list.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
header('Content-Type: application/json');

$limit = (int)($_GET['limit'] ?? 25);
if ($limit < 1 || $limit > 100) $limit = 25;
$archived = (int)($_GET['archived'] ?? 0);

$hasArchived = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='archived'")->fetchColumn();

[$scopeWhere, $scopeParams] = scope_clause($pdo, 'c', (int)$_SESSION['user']['id']);

$sql = "SELECT c.id, c.name, c.email, c.organization_id, o.name as organization_name FROM clients c LEFT JOIN organizations o ON c.organization_id=o.id";
$params = [];
if ($hasArchived) {
    $sql .= " WHERE c.archived=?";
    $params[] = $archived;
}
$sql .= $scopeWhere;
$sql .= " ORDER BY c.name LIMIT ?";
$params = array_merge($params, $scopeParams, [$limit]);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_NUMERIC_CHECK);
