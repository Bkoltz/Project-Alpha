<?php
// src/controllers/quote_approve.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/project_id.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';

// CSRF verification
require_once __DIR__ . '/../../utils/csrf.php';
if (!csrf_validate()) {
    header('Location: /?page=quote/quotes-list&error=' . urlencode('Invalid request (CSRF)'));
    exit;
}

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

  // Get project_id from quote for inheritance
  $projectId = !empty($quote['project_id']) ? (int)$quote['project_id'] : null;

<<<<<<< HEAD
  // Determine contract type from quote_type
  $contractType = $quote['quote_type'] ?? 'regular';

  if ($contractType === 'on_demand') {
    // Create on-demand contract in unified table
    $pdo->prepare('INSERT INTO contracts (quote_id, client_id, project_id, contract_type, status, discount_type, discount_value, tax_percent, subtotal, price_per_invoice, deposit_type, deposit_amount, deposit_paid, project_code, start_date, end_date, billing_interval_count, billing_interval_unit, scope) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
=======
  // Check if this is an on-demand quote
  if (!empty($quote['is_on_demand'])) {
    // Create on-demand contract with project_id
    $pdo->prepare('INSERT INTO on_demand_contracts (quote_id, client_id, project_id, status, discount_type, discount_value, tax_percent, subtotal, price_per_invoice, deposit_type, deposit_amount, deposit_paid, project_code, start_date, end_date, billing_interval_count, billing_interval_unit, scope) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
>>>>>>> dev
        ->execute([
          $id, 
          (int)$quote['client_id'],
          $projectId,
          'pending', 
          $quote['discount_type'], 
          $quote['discount_value'], 
          $quote['tax_percent'], 
          $quote['subtotal'], 
          $quote['price_per_invoice'],
          $quote['deposit_type'] ?? 'none',
          $quote['deposit_amount'] ?? 0,
          0,
          $projectCode, 
          $quote['start_date'],
          $quote['end_date'],
          $quote['billing_interval_count'],
          $quote['billing_interval_unit'],
          $quote['scope']
        ]);
    $contract_id = (int)$pdo->lastInsertId();

<<<<<<< HEAD
    // Create invoice for on-demand contract (deposit invoice)
    $pdo->prepare('INSERT INTO invoices (contract_id, invoice_type, quote_id, client_id, project_id, parent_contract_type, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, project_code, fulfillment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$contract_id, 'on_demand', $id, (int)$quote['client_id'], $projectId, 'on_demand_contract', $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['price_per_invoice'] ?? $quote['total'], 'unpaid', null, $projectCode, $quote['fulfillment_date'] ?? null]);
    $invoice_id = (int)$pdo->lastInsertId();

    $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');
    foreach ($qitems as $it) {
      $ii->execute([$invoice_id, $it['description'] ?? 'Item', $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
    }

    $iMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices')->fetchColumn();
    $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$iMax + 1, $invoice_id]);

  } elseif ($contractType === 'long_term') {
    // Create long-term contract in unified table
    $pdo->prepare('INSERT INTO contracts (quote_id, client_id, project_id, contract_type, status, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_amount, deposit_paid, start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice, scope) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
=======
    // Assign doc_number to on-demand contract
    $cMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM on_demand_contracts')->fetchColumn();
    $pdo->prepare('UPDATE on_demand_contracts SET doc_number=? WHERE id=?')->execute([$cMax + 1, $contract_id]);
  } elseif (!empty($quote['is_long_term'])) {
    // Create long-term contract with project_id
    $pdo->prepare('INSERT INTO long_term_contracts (quote_id, client_id, project_id, status, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_amount, deposit_paid, start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice, scope) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
>>>>>>> dev
        ->execute([
          $id, 
          (int)$quote['client_id'],
          $projectId,
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
      $ci = $pdo->prepare('INSERT INTO contract_items (contract_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');
      foreach ($qitems as $it) {
        $ci->execute([$contract_id, $it['description'] ?? 'Item', $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
      }
    }

<<<<<<< HEAD
    // Create invoice for long-term contract (deposit invoice)
    $pdo->prepare('INSERT INTO invoices (contract_id, invoice_type, quote_id, client_id, project_id, parent_contract_type, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, project_code, fulfillment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$contract_id, 'long_term', $id, (int)$quote['client_id'], $projectId, 'long_term_contract', $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total'], 'unpaid', null, $projectCode, $quote['fulfillment_date'] ?? null]);
    $invoice_id = (int)$pdo->lastInsertId();

    $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');
    foreach ($qitems as $it) {
      $ii->execute([$invoice_id, $it['description'] ?? 'Item', $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
    }

    $iMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices')->fetchColumn();
    $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$iMax + 1, $invoice_id]);

=======
    // Assign doc_number to long-term contract
    $cMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM long_term_contracts')->fetchColumn();
    $pdo->prepare('UPDATE long_term_contracts SET doc_number=? WHERE id=?')->execute([$cMax + 1, $contract_id]);
>>>>>>> dev
  } else {
    // Create regular contract in pending state with project_id (transfer deposit info from quote)
    $pdo->prepare('INSERT INTO contracts (quote_id, client_id, project_id, status, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_amount, deposit_paid, fulfillment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, (int)$quote['client_id'], $projectId, 'pending', $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total'], $projectCode, $quote['deposit_type'] ?? 'none', $quote['deposit_amount'] ?? 0, 0, $quote['fulfillment_date'] ?? null]);
    $contract_id = (int)$pdo->lastInsertId();

    // Contract items from quote items
    $ci = $pdo->prepare('INSERT INTO contract_items (contract_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');
    foreach ($qitems as $it) {
      $ci->execute([$contract_id, $it['description'] ?? 'Item', $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
    }

<<<<<<< HEAD
    // Create invoice
    $pdo->prepare('INSERT INTO invoices (contract_id, invoice_type, quote_id, client_id, project_id, parent_contract_type, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, project_code, fulfillment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$contract_id, 'regular', $id, (int)$quote['client_id'], $projectId, 'contract', $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total'], 'unpaid', null, $projectCode, $quote['fulfillment_date'] ?? null]);
=======
    // Create invoice with no due date (set on completion), includes fulfillment date and project_id from quote
    $pdo->prepare('INSERT INTO invoices (contract_id, quote_id, client_id, project_id, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, project_code, fulfillment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$contract_id, $id, (int)$quote['client_id'], $projectId, $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total'], 'unpaid', null, $projectCode, $quote['fulfillment_date'] ?? null]);
>>>>>>> dev
    $invoice_id = (int)$pdo->lastInsertId();

    $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');
    foreach ($qitems as $it) {
      $ii->execute([$invoice_id, $it['description'] ?? 'Item', $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
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
