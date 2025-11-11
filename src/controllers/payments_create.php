<?php
// src/controllers/payments_create.php
require_once __DIR__ . '/../config/db.php';

$invoice_id = (int)($_POST['invoice_id'] ?? 0);
$amount = (float)($_POST['amount'] ?? 0);
$method = trim((string)($_POST['method'] ?? 'card'));
$check_number = trim((string)($_POST['check_number'] ?? ''));
$paid_in_advance = !empty($_POST['paid_in_advance']);
if ($invoice_id <= 0 || $amount <= 0) {
  header('Location: /?page=payments-create&error=Invalid%20input');
  exit;
}
// Validate check number if method is check
if (strtolower($method) === 'check' && empty($check_number)) {
  header('Location: /?page=payments/payments-create&error=Check%20number%20is%20required');
  exit;
}

$pdo->beginTransaction();
try {
  $pdo->prepare('INSERT INTO payments (invoice_id, amount, method, check_number, status) VALUES (?,?,?,?,?)')
      ->execute([$invoice_id, $amount, $method ?: null, $check_number ?: null, 'succeeded']);

  // Update invoice status by total paid
  $sum = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS paid FROM payments WHERE invoice_id=? AND status="succeeded"');
  $sum->execute([$invoice_id]);
  $paid = (float)$sum->fetchColumn();

  $tot = $pdo->prepare('SELECT total FROM invoices WHERE id=?');
  $tot->execute([$invoice_id]);
  $total = (float)$tot->fetchColumn();

  $status = 'partial';
  if ($paid >= $total) $status = 'paid';
  $pdo->prepare('UPDATE invoices SET status=? WHERE id=?')->execute([$status, $invoice_id]);
  // If invoice status moved out of public-viewable states, revoke public links
  if (!in_array($status, ['unpaid','partial'], true)) {
    try {
      $redir = '/?page=public-redirect&type=invoice&reason=' . rawurlencode($status);
      $rv = $pdo->prepare('UPDATE public_links SET revoked=1, redirect=? WHERE type="invoice" AND record_id=? AND revoked=0');
      $rv->execute([$redir, $invoice_id]);
    } catch (Throwable $_e) { /* ignore revocation failures */ }
  }
  // If invoice paid and linked to contract, mark contract completed (unless paid in advance)
  if ($status === 'paid' && !$paid_in_advance) {
    $co = $pdo->prepare('SELECT contract_id FROM invoices WHERE id=?');
    $co->execute([$invoice_id]);
    $contract_id = (int)$co->fetchColumn();
    if ($contract_id > 0) {
      $pdo->prepare('UPDATE contracts SET status=? WHERE id=?')->execute(['completed', $contract_id]);
    }
  }

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  header('Location: /?page=payments-create&error=Failed%20to%20save%20payment');
  exit;
}

header('Location: /?page=payments-list&saved=1');
exit;