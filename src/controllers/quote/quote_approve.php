<?php
// src/controllers/quote_approve.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/project_id.php';
require_once __DIR__ . '/../../config/app.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: /?page=quote/quotes-list&error=Invalid%20quote');
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

  // Ensure project_code
  $projectCode = $quote['project_code'] ?? null;
  if (!$projectCode) {
    $projectCode = project_next_code($pdo, (int)$quote['client_id']);
    $pdo->prepare('UPDATE quotes SET project_code=? WHERE id=?')->execute([$projectCode, $id]);
  }

  // Mark quote approved
  $pdo->prepare('UPDATE quotes SET status="approved" WHERE id=?')->execute([$id]);

  // Check if this is a long-term quote
  if (!empty($quote['is_long_term'])) {
    // Create long-term contract instead
    $pdo->prepare('INSERT INTO long_term_contracts (quote_id, client_id, status, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_amount, deposit_paid, start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice, scope) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([
          $id, 
          (int)$quote['client_id'], 
          'pending', 
          $quote['discount_type'], 
          $quote['discount_value'], 
          $quote['tax_percent'], 
          $quote['subtotal'], 
          $quote['total'], 
          $projectCode, 
          $quote['deposit_type'] ?? 'none', 
          $quote['deposit_amount'] ?? 0, 
          0,
          $quote['start_date'],
          $quote['end_date'],
          $quote['billing_interval_count'],
          $quote['billing_interval_unit'],
          $quote['pricing_type'],
          $quote['price_per_invoice'],
          $quote['scope']
        ]);
    $contract_id = (int)$pdo->lastInsertId();

    // Long-term contract items from quote items (only if we have items)
    if (!empty($qitems)) {
      $ci = $pdo->prepare('INSERT INTO long_term_contract_items (long_term_contract_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
      foreach ($qitems as $it) {
        $ci->execute([$contract_id, $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
      }
    }

    // Assign doc_number to long-term contract
    $cMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM long_term_contracts')->fetchColumn();
    $pdo->prepare('UPDATE long_term_contracts SET doc_number=? WHERE id=?')->execute([$cMax + 1, $contract_id]);
  } else {
    // Create regular contract in pending state (transfer deposit info from quote)
    $pdo->prepare('INSERT INTO contracts (quote_id, client_id, status, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_amount, deposit_paid, fulfillment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, (int)$quote['client_id'], 'pending', $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total'], $projectCode, $quote['deposit_type'] ?? 'none', $quote['deposit_amount'] ?? 0, 0, $quote['fulfillment_date'] ?? null]);
    $contract_id = (int)$pdo->lastInsertId();

    // Contract items from quote items
    $ci = $pdo->prepare('INSERT INTO contract_items (contract_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
    foreach ($qitems as $it) {
      $ci->execute([$contract_id, $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
    }

    // Create invoice with no due date (set on completion), includes fulfillment date from quote
    $pdo->prepare('INSERT INTO invoices (contract_id, quote_id, client_id, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, project_code, fulfillment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$contract_id, $id, (int)$quote['client_id'], $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total'], 'unpaid', null, $projectCode, $quote['fulfillment_date'] ?? null]);
    $invoice_id = (int)$pdo->lastInsertId();

    $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
    foreach ($qitems as $it) {
      $ii->execute([$invoice_id, $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
    }

    // Assign per-type doc_numbers: do not change quote doc_number here
    $cMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM contracts')->fetchColumn();
    $pdo->prepare('UPDATE contracts SET doc_number=? WHERE id=?')->execute([$cMax + 1, $contract_id]);
    $iMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices')->fetchColumn();
    $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$iMax + 1, $invoice_id]);
  }

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  error_log('[quote_approve] Failed: ' . $e->getMessage());
  header('Location: /?page=quote/quotes-list&error=' . urlencode('Failed to approve: ' . $e->getMessage()));
  exit;
}

header('Location: /?page=quote/quotes-list&approved=1');
exit;
