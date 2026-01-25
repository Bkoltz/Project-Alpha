<?php
// src/controllers/project/projects_search.php
require_once __DIR__ . '/../../config/db.php';

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

// Get projects for this client (direct) OR their organization
// Show active and in_progress projects
if ($orgId) {
    // Client has an organization - show both client projects and org projects
    $st = $pdo->prepare('
        SELECT p.id, p.name, p.status 
        FROM projects p 
        WHERE (p.client_id = ? OR p.organization_id = ?) 
          AND p.status IN ("not_started", "active", "overdue") 
        ORDER BY p.name
    ');
    $st->execute([$clientId, $orgId]);
} else {
    // Client has no organization - show only direct client projects
    $st = $pdo->prepare('
        SELECT p.id, p.name, p.status 
        FROM projects p 
        WHERE p.client_id = ? 
          AND p.status IN ("not_started", "active", "overdue") 
        ORDER BY p.name
    ');
    $st->execute([$clientId]);
}
$projects = $st->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($projects);
?>