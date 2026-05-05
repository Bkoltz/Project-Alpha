<?php
// src/controllers/client/clients_restore.php
// Updated: restores soft-deleted clients from archive_payload
require_once __DIR__ . '/../../config/db.php';

$id = (int)($_POST['id'] ?? 0); // clients.id
if ($id <= 0) {
  header('Location: /?page=client/archived-clients&error=Invalid%20request');
  exit;
}

// Fetch soft-deleted client
$st = $pdo->prepare('SELECT * FROM clients WHERE id=? AND deleted_at IS NOT NULL');
$st->execute([$id]);
$client = $st->fetch(PDO::FETCH_ASSOC);
if (!$client) {
  header('Location: /?page=client/archived-clients&error=Not%20found');
  exit;
}

// Restore: clear deleted_at and archive_payload
$upd = $pdo->prepare('UPDATE clients SET deleted_at = NULL, archive_payload = NULL WHERE id = ?');
$upd->execute([$id]);

header('Location: /?page=client/clients-list&restored=1');
exit;
