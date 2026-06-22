<?php
// src/controllers/payments_create.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/audit.php';

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
  header('Location: /?page=stripe-charge&invoice_id=' . $invoice_id . '&amount=' . $amount);
  exit;
}

if ($invoice_id <= 0 || $amount <= 0) {
  header('Location: /?page=payments/payments-create&error=Invalid%20input');
  exit;
}

// Fetch invoice details to get client_id and contract_id
$invStmt = $pdo->prepare('SELECT client_id, contract_id, organization_id FROM invoices WHERE id = ?');
$invStmt->execute([$invoice_id]);
$invoice = $invStmt->fetch(PDO::FETCH_ASSOC);

$client_id = (int)($invoice['client_id'] ?? 0);
$contract_id = !empty($invoice['contract_id']) ? (int)$invoice['contract_id'] : null;
$organization_id = !empty($invoice['organization_id']) ? (int)$invoice['organization_id'] : null;

// Validate check number if method is check
if (strtolower($method) === 'check' && empty($check_number)) {
  header('Location: /?page=payments/payments-create&error=Check%20number%20is%20required');
  exit;
}

$pdo->beginTransaction();
try {
  $pdo->prepare('INSERT INTO payments (client_id, invoice_id, contract_id, organization_id, amount, payment_method, reference_number, status, payment_date) VALUES (?,?,?,?,?,?,?,?,CURDATE())')
      ->execute([$client_id, $invoice_id, $contract_id, $organization_id, $amount, $method ?: null, $check_number ?: null, 'succeeded']);

  // Update invoice status by total paid
  $sum = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS paid FROM payments WHERE invoice_id=? AND status="succeeded"');
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

  // Send payment received confirmation email if enabled
  try {
    require_once __DIR__ . '/../config/app.php';
    if (!empty($appConfig['payment_received_notification'])) {
      // Get client email and invoice details
      $clientStmt = $pdo->prepare('SELECT c.email, c.name, i.doc_number, i.total FROM clients c JOIN invoices i ON i.client_id = c.id WHERE i.id = ?');
      $clientStmt->execute([$invoice_id]);
      $clientInfo = $clientStmt->fetch(PDO::FETCH_ASSOC);
      
      if ($clientInfo && !empty($clientInfo['email']) && filter_var($clientInfo['email'], FILTER_VALIDATE_EMAIL)) {
        require_once __DIR__ . '/../services/EmailService.php';
        
        $subject = 'Payment Received - Invoice I-' . ($clientInfo['doc_number'] ?? $invoice_id);
        $body = '<p>Dear ' . htmlspecialchars($clientInfo['name'] ?? 'Valued Client') . ',</p>';
        $body .= '<p>We have received your payment of <strong>$' . number_format($amount, 2) . '</strong> ';
        $body .= 'for invoice <strong>I-' . htmlspecialchars($clientInfo['doc_number'] ?? $invoice_id) . '</strong>.</p>';
        if ($status === 'paid') {
          $body .= '<p>This invoice is now <strong>paid in full</strong>. Thank you!</p>';
        } else {
          $body .= '<p>Remaining balance: <strong>$' . number_format($balanceDue, 2) . '</strong>.</p>';
        }
        $body .= '<p>Thank you for your payment!</p>';
        
        EmailService::sendEmail($clientInfo['email'], $subject, $body);
      }
    }
  } catch (Throwable $e) {
    @error_log('[PaymentsCreate] Payment confirmation email error: ' . $e->getMessage());
    // Don't fail the payment because of email
  }

} catch (Throwable $e) {
  $pdo->rollBack();
  @error_log('[PaymentsCreate] Error: ' . $e->getMessage());
  header('Location: /?page=payments/payments-create&error=Failed%20to%20save%20payment');
  exit;
}

header('Location: /?page=payments/payments-list&saved=1');
exit;
