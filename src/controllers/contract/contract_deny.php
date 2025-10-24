<?php
// src/controllers/contract_deny.php
require_once __DIR__ . '/../../config/db.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: /?page=contracts-list&error=Invalid%20contract'); exit; }

$pdo->beginTransaction();
try {
  // Mark contract denied
  $pdo->prepare('UPDATE contracts SET status="denied" WHERE id=?')->execute([$id]);
  // Mark linked invoices denied (do not alter paid ones)
  $pdo->prepare("UPDATE invoices SET status='denied' WHERE contract_id=? AND status<>'paid'")->execute([$id]);
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Location: /?page=contracts-list&error=' . urlencode('Failed to deny: '.$e->getMessage()));
  exit;
}

header('Location: /?page=contracts-list&denied=1');
exit;
