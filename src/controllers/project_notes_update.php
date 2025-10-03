<?php
// src/controllers/project_notes_update.php
require_once __DIR__ . '/../config/db.php';

$project_code = trim((string)($_POST['project_code'] ?? ''));
$client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
$notes = trim((string)($_POST['notes'] ?? ''));
$redirect = $_POST['redirect'] ?? '/?page=projects-list';

if ($project_code === '' || $client_id <= 0) {
  header('Location: '.$redirect.'&error=Invalid%20project%20data');
  exit;
}

try {
  $st = $pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes) VALUES (?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes)');
  $st->execute([$project_code, $client_id, $notes]);
} catch (Throwable $e) {
  header('Location: '.$redirect.'&error=Failed%20to%20save%20notes');
  exit;
}

header('Location: '.$redirect.'&saved=1');
exit;
