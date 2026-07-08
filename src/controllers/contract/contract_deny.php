<?php
// src/controllers/contract_deny.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/public_links.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: /?page=contract/contracts-list&error=Invalid%20contract'); exit; }
require_record_ownership($pdo, 'contracts', $id);

$pdo->beginTransaction();
try {
  // Mark contract denied
  $pdo->prepare('UPDATE contracts SET status="denied" WHERE id=?')->execute([$id]);
  pa_public_link_terminalize($pdo, 'contract', $id, 'denied');
  // Mark linked invoices denied (do not alter paid ones)
  $pdo->prepare("UPDATE invoices SET status='denied' WHERE contract_id=? AND status<>'paid'")->execute([$id]);
  // Revoke public links for affected invoices
  try {
    $invoiceIds = $pdo->prepare('SELECT id FROM invoices WHERE contract_id=? AND status<>"paid"');
    $invoiceIds->execute([$id]);
    foreach ($invoiceIds->fetchAll(PDO::FETCH_COLUMN) as $invoiceId) {
      pa_public_link_terminalize($pdo, 'invoice', (int)$invoiceId, 'denied');
    }
  } catch (Throwable $_e) { /* ignore */ }
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Location: /?page=contract/contracts-list&error=' . urlencode('Failed to deny: '.$e->getMessage()));
  exit;
}

header('Location: /?page=contract/contracts-list&denied=1');
exit;
