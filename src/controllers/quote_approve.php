<?php
// src/controllers/quote_approve.php
require_once __DIR__ . '/../config/db.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: /?page=quotes-list&error=Invalid%20quote');
  exit;
}

$pdo->beginTransaction();
try {
  // Load quote + items
  $q = $pdo->prepare('SELECT * FROM quotes WHERE id=? FOR UPDATE');
  $q->execute([$id]);
  $quote = $q->fetch(PDO::FETCH_ASSOC);
  if (!$quote) throw new Exception('Quote not found');
  if ($quote['status'] !== 'pending') throw new Exception('Quote not pending');
  $items = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id=?');
  $items->execute([$id]);
  $qitems = $items->fetchAll(PDO::FETCH_ASSOC);

  // Mark quote approved
  $pdo->prepare('UPDATE quotes SET status="approved" WHERE id=?')->execute([$id]);

  // Create contract
  $pdo->prepare('INSERT INTO contracts (quote_id, client_id, status, discount_type, discount_value, tax_percent, subtotal, total) VALUES (?,?,?,?,?,?,?,?)')
      ->execute([$id, (int)$quote['client_id'], 'active', $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total']]);
  $contract_id = (int)$pdo->lastInsertId();
  $ci = $pdo->prepare('INSERT INTO contract_items (contract_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
  foreach ($qitems as $it) {
    $ci->execute([$contract_id, $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
  }

  // Also create invoice from the approved quote
  $pdo->prepare('INSERT INTO invoices (contract_id, quote_id, client_id, discount_type, discount_value, tax_percent, subtotal, total, status, due_date) VALUES (?,?,?,?,?,?,?,?,?,?)')
      ->execute([$contract_id, $id, (int)$quote['client_id'], $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total'], 'unpaid', null]);
  $invoice_id = (int)$pdo->lastInsertId();
  $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
  foreach ($qitems as $it) {
    $ii->execute([$invoice_id, $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
  }

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  header('Location: /?page=quotes-list&error=' . urlencode('Failed to approve: ' . $e->getMessage()));
  exit;
}

header('Location: /?page=quotes-list&approved=1');
exit;
