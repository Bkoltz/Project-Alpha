<?php
// src/controllers/quotes_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/project_id.php';

$client_id = (int)($_POST['client_id'] ?? 0);
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$deposit_type = in_array(($_POST['deposit_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['deposit_type'] : 'none';
$deposit_value = (float)($_POST['deposit_value'] ?? 0);
$fulfillment_date = !empty($_POST['fulfillment_date']) ? $_POST['fulfillment_date'] : null;

// Long-term quote fields
$is_long_term = isset($_POST['is_long_term']) ? 1 : 0;

// Only process long-term fields if this is a long-term quote
if ($is_long_term) {
    $start_date = !empty($_POST['lt_start_date']) ? $_POST['lt_start_date'] : null;
    $end_date_type = $_POST['lt_end_date_type'] ?? 'ongoing';
    $end_date = ($end_date_type === 'fixed' && !empty($_POST['lt_end_date'])) ? $_POST['lt_end_date'] : null;
    $billing_interval_count = (int)($_POST['lt_billing_interval_count'] ?? 1);
    $billing_interval_unit = in_array(($_POST['lt_billing_interval_unit'] ?? 'month'), ['day','week','month','year']) ? $_POST['lt_billing_interval_unit'] : 'month';
    $pricing_type = in_array(($_POST['lt_pricing_type'] ?? 'per_invoice'), ['per_invoice','fixed_total']) ? $_POST['lt_pricing_type'] : 'per_invoice';
    $price_per_invoice = ($pricing_type === 'per_invoice') ? (float)($_POST['lt_price_per_invoice'] ?? 0) : null;
    $scope = trim((string)($_POST['scope'] ?? ''));
} else {
    // Set defaults for regular quotes
    $start_date = null;
    $end_date = null;
    $billing_interval_count = 1;
    $billing_interval_unit = 'month';
    $pricing_type = null;
    $price_per_invoice = null;
    $scope = '';
}

$desc = $_POST['item_desc'] ?? [];
$qty = $_POST['item_qty'] ?? [];
$price = $_POST['item_price'] ?? [];

if ($client_id <= 0 || empty($desc)) {
    header('Location: /?page=quote/quotes-create&error=Invalid%20input');
    exit;
}

$items = [];
$subtotal = 0.0;

// For long-term quotes with per_invoice pricing, items are optional
if ($is_long_term && $pricing_type === 'per_invoice') {
    $subtotal = $price_per_invoice;
} else {
    // Process items for regular quotes or fixed_total long-term quotes
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
        header('Location: /?page=quote/quotes-create&error=Add%20at%20least%20one%20item');
        exit;
    }
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
    $stmt = $pdo->prepare('INSERT INTO quotes (client_id, status, discount_type, discount_value, tax_percent, subtotal, total, deposit_type, deposit_amount, fulfillment_date, is_long_term, start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice, scope) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$client_id, 'pending', $discount_type, $discount_value, $tax_percent, $subtotal, $total, $deposit_type, $deposit_value, $fulfillment_date, $is_long_term, $start_date, $end_date, $billing_interval_count, $billing_interval_unit, $pricing_type, $price_per_invoice, $scope]);
    $quote_id = (int)$pdo->lastInsertId();
    // Assign a new Project ID for this quote
    $projectCode = project_next_code($pdo, $client_id);
    $pdo->prepare('UPDATE quotes SET project_code=? WHERE id=?')->execute([$projectCode, $quote_id]);
    // Upsert project notes if provided
    $notes = trim((string)($_POST['project_notes'] ?? ''));
    if ($notes !== '') {
      $up = $pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes) VALUES (?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes)');
      $up->execute([$projectCode, $client_id, $notes]);
    }
    // Assign per-type doc_number for quotes (separate sequences for regular vs long-term)
    if ($is_long_term) {
        $qMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM quotes WHERE is_long_term=1')->fetchColumn();
    } else {
        $qMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM quotes WHERE is_long_term=0')->fetchColumn();
    }
    $pdo->prepare('UPDATE quotes SET doc_number=? WHERE id=?')->execute([$qMax + 1, $quote_id]);

    // Only insert items if we have them (not needed for per_invoice long-term quotes)
    if (!empty($items)) {
        $qi = $pdo->prepare('INSERT INTO quote_items (quote_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
        foreach ($items as $it) {
            $qi->execute([$quote_id, $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    header('Location: /?page=quote/quotes-create&error=Failed%20to%20create%20quote');
    exit;
}

header('Location: /?page=quote/quotes-list&created=1');
exit;