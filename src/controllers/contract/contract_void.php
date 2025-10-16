<?php
// src/controllers/contract_void.php
require_once __DIR__ . '/../config/db.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: /?page=contracts-list&error=Invalid%20contract'); exit; }

$pdo->beginTransaction();
try {
  $st = $pdo->prepare('SELECT * FROM contracts WHERE id=? FOR UPDATE');
  $st->execute([$id]);
  $co = $st->fetch(PDO::FETCH_ASSOC);
  if (!$co) throw new Exception('Contract not found');

  // Contracts enum historically doesn't include 'void' in older schemas; set to 'cancelled' to avoid enum truncation
  $pdo->prepare("UPDATE contracts SET status='cancelled', voided_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);

  // Void related invoices (invoices.status ENUM does include 'void')
  $pdo->prepare("UPDATE invoices SET status='void' WHERE contract_id=?")->execute([$id]);

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Location: /?page=contracts-list&error=' . urlencode($e->getMessage()));
  exit;
}

header('Location: /?page=contracts-list&voided=1');
exit;
