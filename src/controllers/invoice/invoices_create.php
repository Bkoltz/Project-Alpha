<?php
// src/controllers/invoices_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/project_id.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/document_fields.php';

$client_id = (int)($_POST['client_id'] ?? 0);
$project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$due_date = $_POST['due_date'] ?? null;
if (!$due_date || trim($due_date) === '') {
    $netDays = (int)($appConfig['net_terms_days'] ?? 30); if ($netDays < 0) { $netDays = 0; }
    $due_date = date('Y-m-d', strtotime('+' . $netDays . ' days'));
}

$item = $_POST['item'] ?? [];
$desc = $_POST['item_desc'] ?? [];
$qty = $_POST['item_qty'] ?? [];
$price = $_POST['item_price'] ?? [];

if ($client_id <= 0 || empty($item)) {
    header('Location: /?page=invoice/invoices-create&error=Invalid%20input');
    exit;
}

$items = [];
$subtotal = 0.0;
for ($i=0; $i<count($item); $i++) {
    $itm = trim((string)($item[$i] ?? ''));
    $d = trim((string)($desc[$i] ?? ''));
    $q = (float)($qty[$i] ?? 0);
    $p = (float)($price[$i] ?? 0);
    if ($itm === '' || $q <= 0 || $p < 0) continue;
    $line = $q * $p;
    $subtotal += $line;
    $items[] = ['item'=>$itm,'description'=>$d,'quantity'=>$q,'unit_price'=>$p,'line_total'=>$line];
}
if (!$items) {
    header('Location: /?page=invoice/invoices-create&error=Add%20at%20least%20one%20item');
    exit;
}

$discount_amount = 0.0;
if ($discount_type === 'percent') {
    $discount_amount = max(0.0, min(100.0, $discount_value)) * $subtotal / 100.0;
} elseif ($discount_type === 'fixed') {
    $discount_amount = max(0.0, $discount_value);
}
$tax_amount = max(0.0, $tax_percent) * max(0.0, $subtotal - $discount_amount) / 100.0;
$total = max(0.0, $subtotal - $discount_amount + $tax_amount);

// Extract custom field values from POST data (only non-empty values)
$customFields = extractCustomFieldValues($_POST);
$customFieldsJson = !empty($customFields) ? json_encode($customFields) : null;

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO invoices (client_id, project_id, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, custom_fields) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$client_id, $project_id, $discount_type, $discount_value, $tax_percent, $subtotal, $total, 'unpaid', $due_date ?: null, $customFieldsJson]);
    $invoice_id = (int)$pdo->lastInsertId();
    // Assign a new Project ID and doc_number
    $projectCode = project_next_code($pdo, $client_id);
    $pdo->prepare('UPDATE invoices SET project_code=? WHERE id=?')->execute([$projectCode, $invoice_id]);
    $notes = trim((string)($_POST['project_notes'] ?? ''));
    if ($notes !== '') {
      $up = $pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes) VALUES (?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes)');
      $up->execute([$projectCode, $client_id, $notes]);
    }
    // Assign per-type doc_number for invoices
    $iMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices')->fetchColumn();
    $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$iMax + 1, $invoice_id]);

    $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');
    foreach ($items as $it) {
        $ii->execute([$invoice_id, $it['item'], $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
    }
    
    // Add to project_documents if project_id is set
    if ($project_id) {
        $pdo->prepare('INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?, "invoice", ?)')->execute([$project_id, $invoice_id]);
    }
    
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    header('Location: /?page=invoice/invoices-create&error=Failed%20to%20create%20invoice');
    exit;
}

header('Location: /?page=invoice/invoices-list&created=1');
exit;