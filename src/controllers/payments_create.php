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
$check_number = trim((string)($_POST['reference_number'] ?? $_POST['check_number'] ?? ''));
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
invoice_ensure_payments_schema($pdo);
$paidStmt = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount-refunded_amount-disputed_amount,0)),0) FROM payments WHERE invoice_id=? AND status="succeeded"');
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
  $result = invoice_record_locked_payment(
      $pdo,
      $invoice_id,
      $amount,
      $method ?: 'cash',
      $check_number ?: null,
      null,
      [
          'organization_id' => $organization_id,
          'complete_contract_when_paid' => !$paid_in_advance,
          'source' => 'manual_payment',
      ]
  );
  $paymentId = (int)$result['payment_id'];
  $status = (string)$result['status'];

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
  $message = trim($e->getMessage()) !== '' ? substr($e->getMessage(), 0, 180) : 'Failed to save payment';
  header('Location: /?page=payments/payments-create&error=' . urlencode($message));
  exit;
}

header('Location: /?page=payments/payments-list&saved=1');
exit;
