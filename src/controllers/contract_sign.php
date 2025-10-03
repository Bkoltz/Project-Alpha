<?php
// src/controllers/contract_sign.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

$contract_id = (int)($_POST['id'] ?? 0);
if ($contract_id <= 0) { header('Location: /?page=contracts-list&error=Invalid%20contract'); exit; }

$pdo->beginTransaction();
try {
  $c = $pdo->prepare('SELECT * FROM contracts WHERE id=? FOR UPDATE');
  $c->execute([$contract_id]);
  $contract = $c->fetch(PDO::FETCH_ASSOC);
  if (!$contract) throw new Exception('Not found');

  // Sum items and read totals/discount/tax from contracts (after migration adds fields), fallback to compute from items
  $it = $pdo->prepare('SELECT description, quantity, unit_price, line_total FROM contract_items WHERE contract_id=?');
  $it->execute([$contract_id]);
  $items = $it->fetchAll(PDO::FETCH_ASSOC);
  $subtotal = array_reduce($items, function($s,$i){return $s + (float)$i['line_total'];}, 0.0);
  $discount_type = $contract['discount_type'] ?? 'none';
  $discount_value = (float)($contract['discount_value'] ?? 0);
  $tax_percent = (float)($contract['tax_percent'] ?? 0);

  // Fallback compute if contract doesn't have totals yet
  $discount_amount = 0.0;
  if ($discount_type === 'percent') $discount_amount = max(0.0, min(100.0,$discount_value))*$subtotal/100.0;
  elseif ($discount_type === 'fixed') $discount_amount = max(0.0, $discount_value);
  $tax_amount = max(0.0, $tax_percent)*max(0.0,$subtotal-$discount_amount)/100.0;
  $total = max(0.0, $subtotal - $discount_amount + $tax_amount);

  // Update contract status to active (signed)
  $pdo->prepare('UPDATE contracts SET status=? WHERE id=?')->execute(['active',$contract_id]);

  // Create invoice
  $netDays = (int)($appConfig['net_terms_days'] ?? 30); if ($netDays < 0) { $netDays = 0; }
  $dueDate = date('Y-m-d', strtotime('+' . $netDays . ' days'));
  $pdo->prepare('INSERT INTO invoices (contract_id, quote_id, client_id, discount_type, discount_value, tax_percent, subtotal, total, status, due_date) VALUES (?,?,?,?,?,?,?,?,?,?)')
      ->execute([$contract_id, $contract['quote_id'] ?? null, (int)$contract['client_id'], $discount_type, $discount_value, $tax_percent, $subtotal, $total, 'unpaid', $dueDate]);
  $invoice_id = (int)$pdo->lastInsertId();

  $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
  foreach ($items as $row) {
    $ii->execute([$invoice_id, $row['description'], $row['quantity'], $row['unit_price'], $row['line_total']]);
  }

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  header('Location: /?page=contracts-list&error=' . urlencode($e->getMessage()));
  exit;
}

header('Location: /?page=invoices-list&created=1');
exit;
