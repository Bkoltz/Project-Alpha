<?php
// src/controllers/invoices_update.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/document_fields.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/acl_middleware.php';
$id = (int)($_POST['id'] ?? 0);
require_record_ownership($pdo, 'invoices', $id);
$statusStmt = $pdo->prepare('SELECT status FROM invoices WHERE id=?');
$statusStmt->execute([$id]);
if (strtolower((string)$statusStmt->fetchColumn()) !== 'draft') {
  header('Location: /?page=invoice/invoice-details&id=' . $id . '&error=' . urlencode('Reopen the invoice as a draft before editing it.'));
  exit;
}
$client_id = (int)($_POST['client_id'] ?? 0);
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$billing_mode = ($_POST['billing_mode'] ?? 'fixed') === 'hourly' ? 'hourly' : 'fixed';
$due_date = $_POST['due_date'] ?? null;
$fulfillment_date = !empty($_POST['fulfillment_date']) ? $_POST['fulfillment_date'] : null;
if ($id<=0 || $client_id<=0) { header('Location: /?page=invoice/invoices-list&error=Invalid'); exit; }

// Detect whether the DB has the is_extra_charge column (migration may not have been applied)
$hasExtraChargeCol = false;
try {
  $colStmt = $pdo->prepare("SHOW COLUMNS FROM invoice_items LIKE 'is_extra_charge'");
  $colStmt->execute();
  $hasExtraChargeCol = (bool)$colStmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // If the query fails, assume column is missing and continue (migration step required)
  $hasExtraChargeCol = false;
}

// Fetch existing items to preserve contract items and update extra charges
if ($hasExtraChargeCol) {
  $existingItems = $pdo->prepare('SELECT id, is_extra_charge FROM invoice_items WHERE invoice_id=?');
  $existingItems->execute([$id]);
  $existingMap = [];
  foreach ($existingItems->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $existingMap[$row['id']] = (int)$row['is_extra_charge'];
  }
} else {
  // Older schema: no extra charge column, treat all existing items as contract items (0)
  $existingItems = $pdo->prepare('SELECT id FROM invoice_items WHERE invoice_id=?');
  $existingItems->execute([$id]);
  $existingMap = [];
  foreach ($existingItems->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $existingMap[$row['id']] = 0;
  }
}

// Process extra charges (new and updated)
$extraItems = $_POST['extra_item'] ?? [];
$extraDescs = $_POST['extra_desc'] ?? [];
$extraQtys = $_POST['extra_qty'] ?? [];
$ExtraPrices = $_POST['extra_price'] ?? [];
$extraIds = $_POST['extra_id'] ?? [];
$extraUnits = $_POST['extra_billing_unit'] ?? [];

$extraItemsArr = [];
$subtotal = 0.0;

for ($i = 0; $i < count($extraItems); $i++) {
  $itm = trim((string)($extraItems[$i] ?? ''));
  $d = trim((string)($extraDescs[$i] ?? ''));
  $q = (float)($extraQtys[$i] ?? 0);
  $p = (float)($ExtraPrices[$i] ?? 0);
  $eid = (int)($extraIds[$i] ?? 0);
  
  if ($itm === '' || $q <= 0 || $p < 0) continue;
  
  $line = $q * $p;
  $subtotal += $line;
  $unit = (($extraUnits[$i] ?? 'each') === 'hour' || $billing_mode === 'hourly') ? 'hour' : 'each';
  $extraItemsArr[] = ['id' => $eid, 'i' => $itm, 'd' => $d, 'q' => $q, 'p' => $p, 't' => $line, 'u' => $unit];
}

// Fetch all existing items to calculate subtotal including contract items
$allExistingItems = $pdo->prepare('SELECT description, quantity, unit_price FROM invoice_items WHERE invoice_id=?');
$allExistingItems->execute([$id]);
$contractSubtotal = 0.0;
foreach ($allExistingItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
  // Only count non-extra charge items (contract items)
  $contractSubtotal += (float)$item['quantity'] * (float)$item['unit_price'];
}
$subtotal = $contractSubtotal; // Reset and add extras

// Add extra charges subtotal
foreach ($extraItemsArr as $ex) {
  $subtotal += $ex['t'];
}

$discount_amount = 0.0;
if ($discount_type === 'percent') {
  $discount_amount = max(0, min(100, $discount_value)) * $subtotal / 100;
} elseif ($discount_type === 'fixed') {
  $discount_amount = max(0, $discount_value);
}
$tax = max(0, $tax_percent) * max(0, $subtotal - $discount_amount) / 100;
$total = max(0, $subtotal - $discount_amount + $tax);

// Extract custom field values from POST
$customFieldValues = extractCustomFieldValues($_POST);
$customFieldsJson = !empty($customFieldValues) ? json_encode($customFieldValues) : null;

$pdo->beginTransaction();
try {
  $pdo->prepare('UPDATE invoices SET client_id=?, billing_mode=?, discount_type=?, discount_value=?, tax_percent=?, subtotal=?, total=?, due_date=?, fulfillment_date=?, custom_fields=? WHERE id=?')
    ->execute([$client_id, $billing_mode, $discount_type, $discount_value, $tax_percent, $subtotal, $total, $due_date ?: null, $fulfillment_date, $customFieldsJson, $id]);
  
  $row = $pdo->prepare('SELECT project_code FROM invoices WHERE id=?');
  $row->execute([$id]);
  $pc = (string)$row->fetchColumn();
  $pn = trim((string)($_POST['project_notes'] ?? ''));
  if ($pc !== '' && $pn !== '') {
    $up = $pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes) VALUES (?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes)');
    $up->execute([$pc, $client_id, $pn]);
  }
  
  // Delete only extra charge items (if column exists), otherwise do not delete contract items
  if ($hasExtraChargeCol) {
    $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id=? AND is_extra_charge=1')->execute([$id]);

    // Insert new extra charges with the flag
    $ins = $pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total, billing_unit, is_extra_charge) VALUES (?,?,?,?,?,?,?,1)');
    foreach ($extraItemsArr as $it) {
      $ins->execute([$id, $it['i'], $it['d'], $it['q'], $it['p'], $it['t'], $it['u']]);
    }
  } else {
    // Schema doesn't have is_extra_charge yet: append entries as regular invoice_items
    $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total, billing_unit) VALUES (?,?,?,?,?,?,?)');
    foreach ($extraItemsArr as $it) {
      $ii->execute([$id, $it['i'], $it['d'], $it['q'], $it['p'], $it['t'], $it['u']]);
    }
  }
  
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  header('Location: /?page=invoice/invoice-details&id=' . $id . '&error=Update%20failed');
  exit;
}
header('Location: /?page=invoice/invoice-details&id=' . $id . '&updated=1');
exit;
