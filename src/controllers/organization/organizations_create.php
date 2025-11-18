<?php
// src/controllers/organization/organizations_create.php
require_once __DIR__ . '/../../config/db.php';

$name = trim($_POST['name'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if ($name === '') {
    header('Location: /?page=organization/organizations-create&error=Name%20is%20required');
    exit;
}

$stmt = $pdo->prepare('INSERT INTO organizations (name, notes) VALUES (?, ?)');
$stmt->execute([
    $name,
    $notes ?: null
]);

header('Location: /?page=organization/organizations-list&created=1');
exit;
