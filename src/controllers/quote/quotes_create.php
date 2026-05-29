<?php
// src/controllers/quotes_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/project_id.php';
require_once __DIR__ . '/../../utils/document_fields.php';

$client_id = (int)($_POST['client_id'] ?? 0);
$project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none', 'percent', 'fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
// Check both direct field names and custom_field_ prefixed names (from dynamic rendering)
$deposit_type = $_POST['deposit_type'] ?? $_POST['custom_field_deposit_type'] ?? 'none';
$deposit_type = in_array($deposit_type, ['none','percent','fixed']) ? $deposit_type : 'none';
$deposit_value = (float)($_POST['deposit_value'] ?? $_POST['custom_field_deposit_value'] ?? 0);
$fulfillment_date = $_POST['fulfillment_date'] ?? $_POST['custom_field_fulfillment_date'] ?? null;
$fulfillment_date = !empty($fulfillment_date) ? $fulfillment_date : null;

// Document type from radio buttons
$doc_type = $_POST['doc_type'] ?? 'regular';
$quote_type = in_array($doc_type, ['regular', 'long_term', 'on_demand'], true) ? $doc_type : 'regular';
$is_long_term = ($quote_type === 'long_term') ? 1 : 0;
$is_on_demand = ($quote_type === 'on_demand') ? 1 : 0;

// Only process long-term/on-demand fields if not regular
if ($is_long_term || $is_on_demand) {
    $start_date = !empty($_POST['lt_start_date']) ? $_POST['lt_start_date'] : null;
    $end_date_type = $_POST['lt_end_date_type'] ?? 'ongoing';
    $end_date = ($end_date_type === 'fixed' && !empty($_POST['lt_end_date'])) ? $_POST['lt_end_date'] : null;
    $pricing_type = in_array(($_POST['lt_pricing_type'] ?? 'per_invoice'), ['per_invoice', 'fixed_total', 'on_demand']) ? $_POST['lt_pricing_type'] : 'per_invoice';

    // Check if this is an on-demand quote
    if ($pricing_type === 'on_demand') {
        $is_on_demand = 1;
        $quote_type = 'on_demand';
        $billing_interval_count = 1;
        $billing_interval_unit = 'month';
    } else {
        $billing_interval_count = (int)($_POST['lt_billing_interval_count'] ?? 1);
        $billing_interval_unit = in_array(($_POST['lt_billing_interval_unit'] ?? 'month'), ['day', 'week', 'month', 'year']) ? $_POST['lt_billing_interval_unit'] : 'month';
    }

    $price_per_invoice = ($pricing_type === 'per_invoice' || $pricing_type === 'on_demand') ? (float)($_POST['lt_price_per_invoice'] ?? 0) : null;
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

$item = $_POST['item'] ?? [];
$desc = $_POST['item_desc'] ?? [];
$qty = $_POST['item_qty'] ?? [];
$price = $_POST['item_price'] ?? [];

// Validate client_id
if ($client_id <= 0) {
    header('Location: /?page=quote/quotes-create&error=Please%20select%20a%20client');
    exit;
}

// For long-term quotes with per_invoice or on_demand pricing, items are optional
$requires_items = !($is_long_term && ($pricing_type === 'per_invoice' || $pricing_type === 'on_demand'));

if ($requires_items && empty($item)) {
    header('Location: /?page=quote/quotes-create&error=Add%20at%20least%20one%20item');
    exit;
}

$items = [];
$subtotal = 0.0;

// For long-term quotes with per_invoice or on_demand pricing, items are optional
if ($is_long_term && ($pricing_type === 'per_invoice' || $pricing_type === 'on_demand')) {
    $subtotal = $price_per_invoice;
} else {
    // Process items for regular quotes or fixed_total long-term quotes
    for ($i = 0; $i < count($item); $i++) {
        $itm = trim((string)($item[$i] ?? ''));
        $d = trim((string)($desc[$i] ?? ''));
        $q = (float)($qty[$i] ?? 0);
        $p = (float)($price[$i] ?? 0);
        if ($itm === '' || $q <= 0 || $p < 0) continue;
        $line = $q * $p;
        $subtotal += $line;
        $items[] = ['item' => $itm, 'description' => $d, 'quantity' => $q, 'unit_price' => $p, 'line_total' => $line];
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

// Extract custom field values from POST data (only non-empty values)
$customFields = extractCustomFieldValues($_POST);
$customFieldsJson = !empty($customFields) ? json_encode($customFields) : null;

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO quotes (client_id, project_id, doc_number, project_code, status, quote_type, discount_type, discount_value, tax_percent, subtotal, total, deposit_type, deposit_amount, fulfillment_date, start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice, scope, custom_fields, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$client_id, $project_id, null, null, 'pending', $quote_type, $discount_type, $discount_value, $tax_percent, $subtotal, $total, $deposit_type, $deposit_value, $fulfillment_date, $start_date, $end_date, $billing_interval_count, $billing_interval_unit, $pricing_type, $price_per_invoice, $scope, $customFieldsJson, date("Y-m-d H:i:s")]);
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

    // Assign per-type doc_number for quotes (separate sequences for regular, long-term, and on-demand)
    $qMaxStmt = $pdo->prepare('SELECT COALESCE(MAX(doc_number),0) FROM quotes WHERE quote_type = ?');
    $qMaxStmt->execute([$quote_type]);
    $qMax = (int)$qMaxStmt->fetchColumn();

    $stmt = $pdo->prepare('UPDATE quotes SET doc_number=? WHERE id=?');
    $stmt->execute([$qMax + 1, $quote_id]);

    // Only insert items if we have them (not needed for per_invoice or on_demand long-term quotes)
    if (!empty($items)) {
        $qi = $pdo->prepare('INSERT INTO quote_items (quote_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');
        foreach ($items as $it) {
            $qi->execute([$quote_id, $it['item'], $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
        }
    }

    // Add to project_documents if project_id is set
    if ($project_id) {
        $pdo->prepare('INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?, "quote", ?)')->execute([$project_id, $quote_id]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();

    error_log($e);

    header('Location: /?page=quote/quotes-create&error=Failed%20to%20create%20quote');
    exit;
}

header('Location: /?page=quote/quotes-list&created=1');
exit;
