<?php
// src/controllers/quote_approve.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/project_id.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: /?page=quotes-list&error=Invalid%20quote');
  exit;
}

// If schedule not provided yet, show a minimal prompt form
if (!isset($_POST['scheduled_date'])) {
  header('Content-Type: text/html; charset=utf-8');
  echo '<section style="max-width:560px;margin:24px auto">';
  echo '<h2>Approve Quote</h2>';
  echo '<form method="post" action="/?page=quote-approve" style="display:grid;gap:12px">';
  echo '<input type="hidden" name="id" value="'.(int)$id.'">';
  echo '<label><div>Scheduled date</div><input type="date" name="scheduled_date" style="padding:10px;border-radius:8px;border:1px solid #ddd;width:100%" required></label>';
  echo '<div style="display:flex;gap:8px">';
  echo '<button type="submit" name="approve" value="1" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Approve</button>';
  echo '<a href="/?page=quotes-list" style="padding:10px 14px;border-radius:8px;border:1px solid #ddd;background:#fff;color:#111;text-decoration:none;display:inline-block">Cancel</a>';
  echo '</div>';
  echo '</form>';
  echo '</section>';
  exit;
}
$estimated = null;
$scheduled_date = isset($_POST['scheduled_date']) && $_POST['scheduled_date'] !== '' ? $_POST['scheduled_date'] : null;
$weather = 0;

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

  // Create contract
  $pdo->prepare('INSERT INTO contracts (quote_id, client_id, status, discount_type, discount_value, tax_percent, subtotal, total, terms, estimated_completion, weather_pending, scheduled_date, project_code) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$id, (int)$quote['client_id'], 'active', $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total'], null, $estimated, $weather, $scheduled_date, $projectCode]);
  $contract_id = (int)$pdo->lastInsertId();
  $ci = $pdo->prepare('INSERT INTO contract_items (contract_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
  foreach ($qitems as $it) {
    $ci->execute([$contract_id, $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
  }

  // Also create invoice from the approved quote (include schedule)
  $pdo->prepare('INSERT INTO invoices (contract_id, quote_id, client_id, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, estimated_completion, weather_pending, scheduled_date, project_code) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$contract_id, $id, (int)$quote['client_id'], $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total'], 'unpaid', null, $estimated, $weather, $scheduled_date, $projectCode]);
  $invoice_id = (int)$pdo->lastInsertId();
  $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
  foreach ($qitems as $it) {
    $ii->execute([$invoice_id, $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
  }

  // Assign doc_number: quote+contract share N, invoice gets N+1
  $docMax = (int)$pdo->query('SELECT GREATEST(
      COALESCE((SELECT MAX(doc_number) FROM quotes),0),
      COALESCE((SELECT MAX(doc_number) FROM contracts),0),
      COALESCE((SELECT MAX(doc_number) FROM invoices),0)
    )')->fetchColumn();
  $n = $docMax + 1;
  $pdo->prepare('UPDATE quotes SET doc_number=? WHERE id=?')->execute([$n, $id]);
  $pdo->prepare('UPDATE contracts SET doc_number=? WHERE id=?')->execute([$n, $contract_id]);
  $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$n + 1, $invoice_id]);

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  header('Location: /?page=quotes-list&error=' . urlencode('Failed to approve: ' . $e->getMessage()));
  exit;
}

header('Location: /?page=quotes-list&approved=1');
exit;
