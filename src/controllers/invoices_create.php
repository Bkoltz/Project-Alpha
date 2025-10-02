<?php
// src/controllers/invoices_create.php
require_once __DIR__ . '/../config/db.php';

$client_id = (int)($_POST['client_id'] ?? 0);
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$due_date = $_POST['due_date'] ?? null;

$desc = $_POST['item_desc'] ?? [];
$qty = $_POST['item_qty'] ?? [];
$price = $_POST['item_price'] ?? [];

if ($client_id <= 0 || empty($desc)) {
    header('Location: /?page=invoices-create&error=Invalid%20input');
    exit;
}

$items = [];
$subtotal = 0.0;
for ($i=0; $i<count($desc); $i++) {
    $d = trim((string)($desc[$i] ?? ''));
    $q = (float)($qty[$i] ?? 0);
    $p = (float)($price[$i] ?? 0);
    if ($d === '' || $q <= 0 || $p < 0) continue;
    $line = $q * $p;
    $subtotal += $line;
    $items[] = ['description'=>$d,'quantity'=>$q,'unit_price'=>$p,'line_total'=>$line];
}
if (!$items) {
    header('Location: /?page=invoices-create&error=Add%20at%20least%20one%20item');
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

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO invoices (client_id, discount_type, discount_value, tax_percent, subtotal, total, status, due_date) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$client_id, $discount_type, $discount_value, $tax_percent, $subtotal, $total, 'unpaid', $due_date ?: null]);
    $invoice_id = (int)$pdo->lastInsertId();
    // Assign a new doc_number
    $docMax = (int)$pdo->query('SELECT GREATEST(
      COALESCE((SELECT MAX(doc_number) FROM quotes),0),
      COALESCE((SELECT MAX(doc_number) FROM contracts),0),
      COALESCE((SELECT MAX(doc_number) FROM invoices),0)
    )')->fetchColumn();
    $docNum = $docMax + 1;
    $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$docNum, $invoice_id]);

    $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
    foreach ($items as $it) {
        $ii->execute([$invoice_id, $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    header('Location: /?page=invoices-create&error=Failed%20to%20create%20invoice');
    exit;
}

header('Location: /?page=invoices-list&created=1');
exit;