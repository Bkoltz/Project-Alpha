<?php
// src/controllers/contract_deny.php
require_once __DIR__ . '/../../config/db.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: /?page=contract/contracts-list&error=Invalid%20contract'); exit; }

$pdo->beginTransaction();
try {
  // Mark contract denied
  $pdo->prepare('UPDATE contracts SET status="denied" WHERE id=?')->execute([$id]);
  // Mark linked invoices denied (do not alter paid ones)
  $pdo->prepare("UPDATE invoices SET status='denied' WHERE contract_id=? AND status<>'paid'")->execute([$id]);
  // Revoke public links for affected invoices
  try {
    $redir = '/?page=public-redirect&type=invoice&reason=denied';
    $pdo->prepare('UPDATE public_links SET revoked=1, redirect=? WHERE document_type="invoice" AND document_id IN (SELECT id FROM invoices WHERE contract_id=? ) AND revoked=0')->execute([$redir, $id]);
  } catch (Throwable $_e) { /* ignore */ }
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Location: /?page=contract/contracts-list&error=' . urlencode('Failed to deny: '.$e->getMessage()));
  exit;
}

header('Location: /?page=contract/contracts-list&denied=1');
exit;
