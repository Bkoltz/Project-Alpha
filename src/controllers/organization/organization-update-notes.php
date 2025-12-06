<?php
// src/controllers/organization/organization-update-notes.php
require_once __DIR__ . '/../../config/db.php';

$id = (int)($_POST['id'] ?? 0);
$notes = trim($_POST['notes'] ?? '');

if (!$id) {
    header('Location: /?page=organization/organizations-list&error=' . urlencode('Invalid organization ID'));
    exit;
}

// Verify organization exists
$checkStmt = $pdo->prepare('SELECT id FROM organizations WHERE id = ?');
$checkStmt->execute([$id]);
if (!$checkStmt->fetch()) {
    header('Location: /?page=organization/organizations-list&error=' . urlencode('Organization not found'));
    exit;
}

// Update notes
$stmt = $pdo->prepare('UPDATE organizations SET notes = ? WHERE id = ?');
$stmt->execute([$notes, $id]);

header('Location: /?page=organization/organization-view&id=' . $id . '&notes_updated=1');
exit;
