<?php
// src/controllers/invoices_mark_paid.php
require_once __DIR__ . '/../config/db.php';
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: /?page=invoices-list&error=Invalid%20invoice'); exit; }

$pdo->beginTransaction();
try{
  $tot = $pdo->prepare('SELECT total FROM invoices WHERE id=? FOR UPDATE');
  $tot->execute([$id]);
  $total = (float)$tot->fetchColumn();
  if (!$total && $total !== 0.0) throw new Exception('Not found');

  $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=? AND status="succeeded"');
  $paidStmt->execute([$id]);
  $paid = (float)$paidStmt->fetchColumn();
  $outstanding = max(0.0, $total - $paid);
  if ($outstanding > 0) {
    $pdo->prepare('INSERT INTO payments (invoice_id, amount, method, status) VALUES (?,?,?,?)')
        ->execute([$id, $outstanding, 'manual', 'succeeded']);
  }
  $pdo->prepare('UPDATE invoices SET status=? WHERE id=?')->execute(['paid',$id]);
  $pdo->commit();
}catch(Throwable $e){
  $pdo->rollBack();
}
header('Location: /?page=invoices-list');
exit;