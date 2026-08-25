<?php
// src/controllers/project/projects_search.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/project_selection.php';

$clientId = (int)($_GET['client_id'] ?? 0);
if (!$clientId) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

$userId = (int)$_SESSION['user']['id'];

[$where, $params] = pa_active_project_filter_for_client($pdo, $clientId);
[$scopeWhere, $scopeParams] = scope_clause($pdo, 'p', $userId);
if ($scopeWhere !== '') {
    $where[] = trim($scopeWhere);
    $params = array_merge($params, $scopeParams);
}
$sql = 'SELECT p.id, p.name, p.status, p.invoice_billing_period FROM projects p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.name';
$st = $pdo->prepare($sql);
$st->execute($params);
$projects = $st->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($projects);
?>
