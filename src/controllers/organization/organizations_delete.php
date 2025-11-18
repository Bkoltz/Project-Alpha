<?php
// src/controllers/organization/organizations_delete.php
require_once __DIR__ . '/../../config/db.php';

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    header('Location: /?page=organization/organizations-list&error=Invalid%20organization');
    exit;
}

// Delete the organization (clients will have organization_id set to NULL via ON DELETE SET NULL)
$stmt = $pdo->prepare('DELETE FROM organizations WHERE id = ?');
$stmt->execute([$id]);

header('Location: /?page=organization/organizations-list&deleted=1');
exit;
