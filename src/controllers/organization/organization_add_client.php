<?php
// src/controllers/organization/organization_add_client.php
require_once __DIR__ . '/../../config/db.php';

$organization_id = (int)($_POST['organization_id'] ?? 0);
$client_id = (int)($_POST['client_id'] ?? 0);

if ($organization_id <= 0 || $client_id <= 0) {
    header('Location: /?page=organization/organizations-list&error=Invalid%20input');
    exit;
}

// Update client to set organization_id
$stmt = $pdo->prepare('UPDATE clients SET organization_id = ? WHERE id = ?');
$stmt->execute([$organization_id, $client_id]);

header('Location: /?page=organization/organization-view&id=' . $organization_id . '&client_added=1');
exit;
