<?php
// src/controllers/contract_void.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: /?page=contract/contracts-list&error=Invalid%20contract'); exit; }
require_record_ownership($pdo, 'contracts', $id);

$pdo->beginTransaction();
try {
  $st = $pdo->prepare('SELECT * FROM contracts WHERE id=? FOR UPDATE');
  $st->execute([$id]);
  $co = $st->fetch(PDO::FETCH_ASSOC);
  if (!$co) throw new Exception('Contract not found');
  $contractType = (string)($co['contract_type'] ?? '');

  // Contracts enum historically doesn't include 'void' in older schemas; set to 'cancelled' to avoid enum truncation
  $pdo->prepare("UPDATE contracts SET status='cancelled', voided_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);

  // Void related invoices (invoices.status ENUM does include 'void')
  $pdo->prepare("UPDATE invoices SET status='void' WHERE contract_id=?")->execute([$id]);
  // Revoke public links for those invoices
  try {
    $redir = '/?page=public-redirect&type=invoice&reason=void';
    $pdo->prepare('UPDATE public_links SET revoked=1, redirect=? WHERE document_type="invoice" AND document_id IN (SELECT id FROM invoices WHERE contract_id=? ) AND revoked=0')->execute([$redir, $id]);
  } catch (Throwable $_e) { /* ignore */ }

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $errorPage = ($contractType ?? '') === 'long_term' ? 'contract/long-term-contract-details' : 'contract/contract-details';
  header('Location: /?page=' . $errorPage . '&id=' . $id . '&error=' . urlencode($e->getMessage()));
  exit;
}

$successPage = ($contractType ?? '') === 'long_term' ? 'contract/long-term-contract-details' : 'contract/contract-details';
header('Location: /?page=' . $successPage . '&id=' . $id . '&voided=1');
exit;
