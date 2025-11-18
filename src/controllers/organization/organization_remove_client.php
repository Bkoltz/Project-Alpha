<?php
// src/controllers/organization/organization_remove_client.php
require_once __DIR__ . '/../../config/db.php';

$organization_id = (int)($_POST['organization_id'] ?? 0);
$client_id = (int)($_POST['client_id'] ?? 0);

if ($organization_id <= 0 || $client_id <= 0) {
    header('Location: /?page=organization/organizations-list&error=Invalid%20input');
    exit;
}

// Remove client from organization by setting organization_id to NULL
$stmt = $pdo->prepare('UPDATE clients SET organization_id = NULL WHERE id = ? AND organization_id = ?');
$stmt->execute([$client_id, $organization_id]);

header('Location: /?page=organization/organization-view&id=' . $organization_id . '&client_removed=1');
exit;
