<?php
// src/controllers/quote_approve.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/project_id.php';
require_once __DIR__ . '/../../config/app.php';

// Auto-create settings (default to true/on when not explicitly set)
$autoCreateContract = !isset($appConfig['quote_auto_create_contract']) || !empty($appConfig['quote_auto_create_contract']);
$autoCreateInvoice = !isset($appConfig['quote_auto_create_invoice']) || !empty($appConfig['quote_auto_create_invoice']);

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

  $quoteType = $quote['quote_type'] ?? 'regular';
  if (in_array($quoteType, ['long_term', 'on_demand'], true)) {
    // For LT/OD quotes, only create contract if auto-create is enabled
    if ($autoCreateContract) {
      $pdo->prepare('INSERT INTO contracts (quote_id, client_id, project_id, status, contract_type, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_amount, deposit_paid, start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice, scope) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([
            $id,
            (int)$quote['client_id'],
            $projectId,
            'pending',
            $quoteType,
            $quote['discount_type'],
            $quote['discount_value'],
            $quote['tax_percent'],
            $quote['subtotal'],
            $quote['total'],
            $projectCode,
            $quote['deposit_type'] ?? 'none',
            $quote['deposit_amount'] ?? 0,
            0,
            $quote['start_date'] ?? null,
            $quote['end_date'] ?? null,
            $quote['billing_interval_count'] ?? 1,
            $quote['billing_interval_unit'] ?? 'month',
            $quote['pricing_type'] ?? ($quoteType === 'on_demand' ? 'on_demand' : null),
            $quote['price_per_invoice'] ?? null,
            $quote['scope'] ?? null
          ]);
      $contract_id = (int)$pdo->lastInsertId();

      if (!empty($qitems)) {
        $ci = $pdo->prepare('INSERT INTO contract_items (contract_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');
        foreach ($qitems as $it) {
          $ci->execute([$contract_id, $it['item'] ?? ($it['description'] ?? 'Item'), $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
        }
      }

      $cMaxStmt = $pdo->prepare('SELECT COALESCE(MAX(doc_number),0) FROM contracts WHERE contract_type = ?');
      $cMaxStmt->execute([$quoteType]);
      $cMax = (int)$cMaxStmt->fetchColumn();
      $pdo->prepare('UPDATE contracts SET doc_number=? WHERE id=?')->execute([$cMax + 1, $contract_id]);
    }
  } else {
    // Regular quote: create contract and/or invoice based on settings
    if ($autoCreateContract) {
      $pdo->prepare('INSERT INTO contracts (quote_id, client_id, project_id, status, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_amount, deposit_paid, fulfillment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$id, (int)$quote['client_id'], $projectId, 'pending', $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total'], $projectCode, $quote['deposit_type'] ?? 'none', $quote['deposit_amount'] ?? 0, 0, $quote['fulfillment_date'] ?? null]);
      $contract_id = (int)$pdo->lastInsertId();

      $ci = $pdo->prepare('INSERT INTO contract_items (contract_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');
      foreach ($qitems as $it) {
        $ci->execute([$contract_id, $it['description'] ?? 'Item', $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
      }

      $cMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM contracts WHERE contract_type = "regular"')->fetchColumn();
      $pdo->prepare('UPDATE contracts SET doc_number=? WHERE id=?')->execute([$cMax + 1, $contract_id]);
    }

    if ($autoCreateInvoice) {
      $pdo->prepare('INSERT INTO invoices (contract_id, quote_id, client_id, project_id, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, project_code, fulfillment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$contract_id ?? null, $id, (int)$quote['client_id'], $projectId, $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total'], 'unpaid', null, $projectCode, $quote['fulfillment_date'] ?? null]);
      $invoice_id = (int)$pdo->lastInsertId();

      $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');
      foreach ($qitems as $it) {
        $ii->execute([$invoice_id, $it['description'] ?? 'Item', $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
      }

      $iMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices WHERE invoice_type = "regular"')->fetchColumn();
      $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$iMax + 1, $invoice_id]);
    }
  }

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  error_log('[quote_approve] Failed: ' . $e->getMessage());
  
  // Determine redirect page based on quote type
  $redirectPage = 'quote/quotes-list';
  if (isset($quoteType)) {
    if ($quoteType === 'long_term') {
      $redirectPage = 'quote/long-term-quotes-list';
    } elseif ($quoteType === 'on_demand') {
      $redirectPage = 'quote/on-demand-quotes-list';
    }
  }
  
  header('Location: /?page=' . $redirectPage . '&error=' . urlencode('Failed to approve: ' . $e->getMessage()));
  exit;
}

// Build flash/redirect info if auto-creation was skipped
$skipped = [];
if (!$autoCreateContract) {
  $skipped[] = 'contract';
}
if (!$autoCreateInvoice) {
  $skipped[] = 'invoice';
}

// Determine redirect page based on quote type
$redirectPage = 'quote/quotes-list';
if ($quoteType === 'long_term') {
  $redirectPage = 'quote/long-term-quotes-list';
} elseif ($quoteType === 'on_demand') {
  $redirectPage = 'quote/on-demand-quotes-list';
}

$redirect = '/?page=' . $redirectPage . '&approved=1';
if (!empty($skipped)) {
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
  $_SESSION['flash_quote_approve'] = [
    'type' => 'info',
    'message' => 'Quote approved, but auto-create ' . implode(' and ', $skipped) . ' ' . (count($skipped) === 1 ? 'is' : 'are') . ' disabled in settings.'
  ];
}
header('Location: ' . $redirect);
exit;
