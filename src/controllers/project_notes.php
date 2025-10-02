<?php
// src/controllers/project_notes.php
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json; charset=utf-8');

$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$projectCode = isset($_GET['project_code']) ? trim((string)$_GET['project_code']) : '';

try {
  if ($projectCode !== '') {
    $st = $pdo->prepare('SELECT notes FROM project_meta WHERE project_code=?');
    $st->execute([$projectCode]);
    $notes = (string)$st->fetchColumn();
    echo json_encode(['notes'=>$notes]);
    exit;
  }
  if ($clientId > 0) {
    // Latest notes by updated_at for client
    $st = $pdo->prepare('SELECT notes FROM project_meta WHERE client_id=? AND notes IS NOT NULL AND notes<>"" ORDER BY updated_at DESC LIMIT 1');
    $st->execute([$clientId]);
    $notes = (string)$st->fetchColumn();
    echo json_encode(['notes'=>$notes]);
    exit;
  }
  echo json_encode(['notes'=>'']);
} catch (Throwable $e) {
  echo json_encode(['notes'=>'']);
}
