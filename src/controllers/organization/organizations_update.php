<?php
// src/controllers/organization/organizations_update.php
require_once __DIR__ . '/../../config/db.php';

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if ($id <= 0 || $name === '') {
    header('Location: /?page=organization/organizations-edit&id=' . $id . '&error=Invalid%20input');
    exit;
}

$stmt = $pdo->prepare('UPDATE organizations SET name = ?, notes = ? WHERE id = ?');
$stmt->execute([
    $name,
    $notes ?: null,
    $id
]);

header('Location: /?page=organization/organizations-list&updated=1');
exit;
