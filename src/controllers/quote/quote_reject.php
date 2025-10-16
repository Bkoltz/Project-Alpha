<?php
// src/controllers/quote_reject.php
require_once __DIR__ . '/../config/db.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: /?page=quotes-list&error=Invalid%20quote');
  exit;
}

try {
  // Only allow reject from pending
  $st = $pdo->prepare('UPDATE quotes SET status="rejected" WHERE id=? AND status="pending"');
  $st->execute([$id]);
  if ($st->rowCount() === 0) {
    header('Location: /?page=quotes-list&error=Cannot%20reject%20this%20quote');
    exit;
  }
} catch (Throwable $e) {
  header('Location: /?page=quotes-list&error=Failed%20to%20reject');
  exit;
}

header('Location: /?page=quotes-list&rejected=1');
exit;
