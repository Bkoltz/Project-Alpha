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

$url = '/?page=payments-create&invoice_id=' . $id;
if ($outstanding > 0) {
  $url .= '&amount=' . urlencode(number_format($outstanding, 2, '.', ''));
}
header('Location: ' . $url);
exit;

  // $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=? AND status="succeeded"');
  $paidStmt->execute([$id]);
  $paid = (float)$paidStmt->fetchColumn();
  $outstanding = max(0.0, $total - $paid);
  if ($outstanding > 0) {
    $pdo->prepare('INSERT INTO payments (invoice_id, amount, method, status) VALUES (?,?,?,?)')
        ->execute([$id, $outstanding, 'manual', 'succeeded']);
  $pdo->prepare('UPDATE invoices SET status=? WHERE id=?')->execute(['paid',$id]);
  // mark related contract completed if exists
  $sel = $pdo->prepare('SELECT contract_id FROM invoices WHERE id=?');
  $sel->execute([$id]);
  $coId = (int)$sel->fetchColumn();
  if ($coId > 0) {
    $pdo->prepare('UPDATE contracts SET status=? WHERE id=?')->execute(['completed',$coId]);
  }
  $pdo->commit();
  // Mark linked contract completed
  $co = $pdo->prepare('SELECT contract_id FROM invoices WHERE id=?');
  $co->execute([$id]);
  $contract_id = (int)$co->fetchColumn();
  if ($contract_id > 0) {
    $pdo->prepare('UPDATE contracts SET status=? WHERE id=?')->execute(['completed', $contract_id]);
  }
  $pdo->commit();
}
// catch(Throwable $e){
//   $pdo->rollBack();
// }
header('Location: /?page=invoice/invoices-list');
exit;