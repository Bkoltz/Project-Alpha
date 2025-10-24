<?php
// src/controllers/clients_purge.php
require_once __DIR__ . '/../../config/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: /?page=client/clients-list&error=Invalid%20client');
  exit;
}

try {
  // Hard delete client; FKs in schema will cascade to child rows
  $st = $pdo->prepare('DELETE FROM clients WHERE id=?');
  $st->execute([$id]);
  header('Location: /?page=client/clients-list&deleted=1');
  exit;
} catch (Throwable $e) {
  header('Location: /?page=client/clients-edit&id='.$id.'&error=Delete%20failed');
  exit;
}
