<?php
// src/controllers/project/projects_search.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';

$clientId = (int)($_GET['client_id'] ?? 0);
if (!$clientId) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

// Get client's organization
$clientStmt = $pdo->prepare('SELECT organization_id FROM clients WHERE id = ?');
$clientStmt->execute([$clientId]);
$clientData = $clientStmt->fetch(PDO::FETCH_ASSOC);
$orgId = $clientData['organization_id'] ?? null;

$userId = (int)$_SESSION['user']['id'];

// Get projects for this client (direct) OR their organization
// Show active and in_progress projects
if ($orgId) {
    // Client has an organization - show both client projects and org projects
    $where = ['(p.client_id = ? OR p.organization_id = ?)', 'p.status IN ("not_started", "active", "overdue")'];
    $params = [$clientId, $orgId];
    [$scopeWhere, $scopeParams] = scope_clause($pdo, 'p', $userId);
    if ($scopeWhere !== '') {
        $where[] = trim($scopeWhere);
        $params = array_merge($params, $scopeParams);
    }
    $sql = 'SELECT p.id, p.name, p.status FROM projects p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.name';
    $st = $pdo->prepare($sql);
    $st->execute($params);
} else {
    // Client has no organization - show only direct client projects
    $where = ['p.client_id = ?', 'p.status IN ("not_started", "active", "overdue")'];
    $params = [$clientId];
    [$scopeWhere, $scopeParams] = scope_clause($pdo, 'p', $userId);
    if ($scopeWhere !== '') {
        $where[] = trim($scopeWhere);
        $params = array_merge($params, $scopeParams);
    }
    $sql = 'SELECT p.id, p.name, p.status FROM projects p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.name';
    $st = $pdo->prepare($sql);
    $st->execute($params);
}
$projects = $st->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($projects);
?>