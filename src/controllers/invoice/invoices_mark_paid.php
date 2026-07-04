<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: /?page=invoice/invoices-list&error=Invalid%20invoice'); exit; }
require_record_ownership($pdo, 'invoices', $id);

$tot = $pdo->prepare('SELECT total FROM invoices WHERE id=? AND status<>"draft" AND finalized_at IS NOT NULL');
$tot->execute([$id]);
$totalValue = $tot->fetchColumn();
if ($totalValue === false) {
  header('Location: /?page=invoice/invoice-details&id=' . $id . '&error=' . urlencode('Finalize the invoice before recording payment.'));
  exit;
}
$total = (float)$totalValue;
$paidStmt = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount-refunded_amount-disputed_amount,0)),0) FROM payments WHERE invoice_id=? AND status="succeeded"');
$paidStmt->execute([$id]);
$paid = (float)$paidStmt->fetchColumn();
$outstanding = max(0.0, $total - $paid);

$url = '/?page=payments/payments-create&invoice_id=' . $id;
if ($outstanding > 0) {
  $url .= '&amount=' . urlencode(number_format($outstanding, 2, '.', ''));
}
header('Location: ' . $url);
exit;
