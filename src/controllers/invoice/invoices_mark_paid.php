<?php
// src/controllers/invoices_mark_paid.php
// Redirect to payment form with invoice preselected and outstanding prefilled
require_once __DIR__ . '/../../config/db.php';
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: /?page=invoice/invoices-list&error=Invalid%20invoice'); exit; }

$tot = $pdo->prepare('SELECT total FROM invoices WHERE id=?');
$tot->execute([$id]);
$total = (float)$tot->fetchColumn();
$paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=? AND status="succeeded"');
$paidStmt->execute([$id]);
$paid = (float)$paidStmt->fetchColumn();
$outstanding = max(0.0, $total - $paid);

$url = '/?page=payments/payments-create&invoice_id=' . $id;
if ($outstanding > 0) {
  $url .= '&amount=' . urlencode(number_format($outstanding, 2, '.', ''));
}
header('Location: ' . $url);
exit;
