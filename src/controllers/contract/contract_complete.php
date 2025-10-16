<?php
// src/controllers/contract_complete.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: /?page=contracts-list&error=Invalid%20contract'); exit; }

$pdo->beginTransaction();
try {
  $st = $pdo->prepare('SELECT * FROM contracts WHERE id=? FOR UPDATE');
  $st->execute([$id]);
  $co = $st->fetch(PDO::FETCH_ASSOC);
  if (!$co) throw new Exception('Contract not found');

  // Mark completed
  $pdo->prepare('UPDATE contracts SET status=?, completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute(['completed', $id]);

  // Set invoice due date if not set
  $netDays = (int)($appConfig['net_terms_days'] ?? 30); if ($netDays < 0) { $netDays = 0; }
  $due = date('Y-m-d', strtotime('+' . $netDays . ' days'));
  $inv = $pdo->prepare('SELECT id, due_date FROM invoices WHERE contract_id=? ORDER BY id DESC LIMIT 1');
  $inv->execute([$id]);
  $invoice = $inv->fetch(PDO::FETCH_ASSOC);
  if ($invoice && empty($invoice['due_date'])) {
    $pdo->prepare('UPDATE invoices SET due_date=? WHERE id=?')->execute([$due, (int)$invoice['id']]);
  }

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Location: /?page=contracts-list&error=' . urlencode($e->getMessage()));
  exit;
}

header('Location: /?page=contracts-list&completed=1');
exit;
