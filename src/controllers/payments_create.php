<?php
// src/controllers/payments_create.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/acl.php';
require_once __DIR__ . '/../utils/audit.php';
require_once __DIR__ . '/../utils/invoice_lifecycle.php';

$invoice_id = (int)($_POST['invoice_id'] ?? 0);
$amount = (float)($_POST['amount'] ?? 0);
$method = trim((string)($_POST['method'] ?? 'card'));
$check_number = trim((string)($_POST['check_number'] ?? ''));
$paid_in_advance = !empty($_POST['paid_in_advance']);

// If Stripe is selected, redirect to Stripe checkout
if (strtolower($method) === 'stripe') {
  if ($invoice_id <= 0) {
    header('Location: /?page=payments/payments-create&error=Please%20select%20an%20invoice');
    exit;
  }
  if (!can_access_record($pdo, 'invoices', $invoice_id, (int)($_SESSION['user']['id'] ?? 0))) {
    header('Location: /?page=payments/payments-create&error=' . urlencode('Permission denied'));
    exit;
  }
  header('Location: /?page=stripe-charge&invoice_id=' . $invoice_id . '&amount=' . $amount);
  exit;
}

if ($invoice_id <= 0 || $amount <= 0) {
  header('Location: /?page=payments/payments-create&error=Invalid%20input');
  exit;
}

// Fetch invoice details to get client_id and contract_id
$invStmt = $pdo->prepare('SELECT client_id,contract_id,organization_id,status,finalized_at,total,collection_mode FROM invoices WHERE id=?');
$invStmt->execute([$invoice_id]);
$invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
if (!can_access_record($pdo, 'invoices', $invoice_id, (int)($_SESSION['user']['id'] ?? 0))) {
  header('Location: /?page=payments/payments-create&error=' . urlencode('Permission denied'));
  exit;
}
if (!$invoice || ($invoice['status'] ?? '') === 'draft' || empty($invoice['finalized_at'])) {
  header('Location: /?page=payments/payments-create&error=' . urlencode('Finalize the invoice before recording payment.'));
  exit;
}
if (($invoice['collection_mode'] ?? 'direct') !== 'direct') {
  header('Location: /?page=payments/payments-create&error=' . urlencode('Record payment from the project invoice so it is allocated correctly.'));
  exit;
}

$client_id = (int)($invoice['client_id'] ?? 0);
$contract_id = !empty($invoice['contract_id']) ? (int)$invoice['contract_id'] : null;
$organization_id = !empty($invoice['organization_id']) ? (int)$invoice['organization_id'] : null;
$paidStmt = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount-refunded_amount,0)),0) FROM payments WHERE invoice_id=? AND status="succeeded"');
$paidStmt->execute([$invoice_id]);
$outstanding = max(0.0, (float)$invoice['total'] - (float)$paidStmt->fetchColumn());
if ($amount > $outstanding + 0.005) {
  header('Location: /?page=payments/payments-create&error=' . urlencode('Payment cannot exceed the outstanding balance.'));
  exit;
}
try {
  invoice_expire_active_checkout($pdo, 'invoices', $invoice_id, $appConfig);
} catch (Throwable $e) {
  header('Location: /?page=payments/payments-create&error=' . urlencode($e->getMessage()));
  exit;
}

// Validate check number if method is check
if (strtolower($method) === 'check' && empty($check_number)) {
  header('Location: /?page=payments/payments-create&error=Check%20number%20is%20required');
  exit;
}

$pdo->beginTransaction();
try {
  $lock = $pdo->prepare('SELECT id,total,status,finalized_at,collection_mode,organization_id FROM invoices WHERE id=? AND organization_id=? FOR UPDATE');
  $lock->execute([$invoice_id, get_active_org_id()]);
  $lockedInvoice = $lock->fetch(PDO::FETCH_ASSOC) ?: [];
  $lockedPaid = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount-refunded_amount,0)),0) FROM payments WHERE invoice_id=? AND status="succeeded"');
  $lockedPaid->execute([$invoice_id]);
  $lockedOutstanding = max(0.0, (float)($lockedInvoice['total'] ?? 0) - (float)$lockedPaid->fetchColumn());
  if (!$lockedInvoice || empty($lockedInvoice['finalized_at']) || ($lockedInvoice['collection_mode'] ?? 'direct') !== 'direct' || $amount > $lockedOutstanding + 0.005) {
    throw new RuntimeException('Invoice balance changed before the payment was recorded.');
  }
  $pdo->prepare('INSERT INTO payments (client_id, invoice_id, contract_id, organization_id, amount, payment_method, reference_number, status, payment_date) VALUES (?,?,?,?,?,?,?,?,CURDATE())')
      ->execute([$client_id, $invoice_id, $contract_id, $organization_id, $amount, $method ?: null, $check_number ?: null, 'succeeded']);
  $paymentId = (int)$pdo->lastInsertId();

  // Update invoice status by total paid
  $sum = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount-refunded_amount,0)),0) AS paid FROM payments WHERE invoice_id=? AND status="succeeded"');
  $sum->execute([$invoice_id]);
  $paid = (float)$sum->fetchColumn();

  $tot = $pdo->prepare('SELECT total FROM invoices WHERE id=?');
  $tot->execute([$invoice_id]);
  $total = (float)$tot->fetchColumn();

  $status = 'partial';
  if ($paid >= $total) $status = 'paid';
  // Update both status, amount_paid, and balance_due on the invoice
  $balanceDue = max(0, $total - $paid);
  $pdo->prepare('UPDATE invoices SET status=?, amount_paid=?, balance_due=? WHERE id=?')
      ->execute([$status, $paid, $balanceDue, $invoice_id]);
  // If invoice status moved out of public-viewable states, revoke public links
  if (!in_array($status, ['unpaid','partial'], true)) {
    try {
      $redir = '/?page=public-redirect&type=invoice&reason=' . rawurlencode($status);
      $rv = $pdo->prepare('UPDATE public_links SET revoked=1, redirect=? WHERE document_type="invoice" AND document_id=? AND revoked=0');
      $rv->execute([$redir, $invoice_id]);
    } catch (Throwable $_e) { /* ignore revocation failures */ }
  }
  // If invoice paid and linked to contract, mark contract completed (unless paid in advance)
  if ($status === 'paid' && !$paid_in_advance) {
    $co = $pdo->prepare('SELECT contract_id FROM invoices WHERE id=?');
    $co->execute([$invoice_id]);
    $cid = (int)$co->fetchColumn();
    if ($cid > 0) {
      $pdo->prepare('UPDATE contracts SET status=? WHERE id=?')->execute(['completed', $cid]);
    }
  }

  $pdo->commit();

  audit_log($pdo, 'payment.recorded', 'invoice', $invoice_id, ['amount' => $amount, 'method' => $method, 'status' => $status]);

  try {
    require_once __DIR__ . '/../utils/payment_receipts.php';
    payment_receipt_issue($pdo, $paymentId, $appConfig ?? []);
  } catch (Throwable $receiptError) {
    @error_log('[PaymentsCreate] Receipt issue failed: ' . $receiptError->getMessage());
  }

} catch (Throwable $e) {
  $pdo->rollBack();
  @error_log('[PaymentsCreate] Error: ' . $e->getMessage());
  header('Location: /?page=payments/payments-create&error=Failed%20to%20save%20payment');
  exit;
}

header('Location: /?page=payments/payments-list&saved=1');
exit;
