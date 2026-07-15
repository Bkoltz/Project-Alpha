<?php
// src/controllers/quotes_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/project_id.php';
require_once __DIR__ . '/../../utils/document_fields.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/project_selection.php';
require_once __DIR__ . '/../../utils/mileage.php';

$__orgId = request_client_org_id() ?: null;
$__creator = (int)($_SESSION['user']['id'] ?? 0) ?: null;

$client_id = (int)($_POST['client_id'] ?? 0);
$project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
$return_to_project = (int)($_POST['return_to_project'] ?? 0);
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none', 'percent', 'fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$billing_mode = ($_POST['billing_mode'] ?? 'fixed') === 'hourly' ? 'hourly' : 'fixed';
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
$od_pricing_mode = 'items';

// Only process long-term/on-demand fields if not regular
if ($is_long_term) {
    // Long-term quote fields
    $start_date = !empty($_POST['lt_start_date']) ? $_POST['lt_start_date'] : null;
    $end_date_type = $_POST['lt_end_date_type'] ?? 'ongoing';
    $end_date = ($end_date_type === 'fixed' && !empty($_POST['lt_end_date'])) ? $_POST['lt_end_date'] : null;
    $pricing_type = in_array(($_POST['lt_pricing_type'] ?? 'per_invoice'), ['per_invoice', 'fixed_total']) ? $_POST['lt_pricing_type'] : 'per_invoice';
    $billing_interval_count = (int)($_POST['lt_billing_interval_count'] ?? 1);
    $billing_interval_unit = in_array(($_POST['lt_billing_interval_unit'] ?? 'month'), ['day', 'week', 'month', 'year']) ? $_POST['lt_billing_interval_unit'] : 'month';
    $price_per_invoice = ($pricing_type === 'per_invoice') ? (float)($_POST['lt_price_per_invoice'] ?? 0) : null;
    $scope = trim((string)($_POST['scope'] ?? ''));
} elseif ($is_on_demand) {
    // On-demand quote fields can use line items or a flat quote amount.
    $start_date = !empty($_POST['od_start_date']) ? $_POST['od_start_date'] : date('Y-m-d');
    $end_date_type = $_POST['od_end_date_type'] ?? 'ongoing';
    $end_date = ($end_date_type === 'fixed' && !empty($_POST['od_end_date'])) ? $_POST['od_end_date'] : null;
    $od_pricing_mode = in_array(($_POST['od_pricing_mode'] ?? 'items'), ['items', 'flat'], true) ? $_POST['od_pricing_mode'] : 'items';
    $pricing_type = 'on_demand';
    $billing_interval_count = 1;
    $billing_interval_unit = 'month';
    $price_per_invoice = null;
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
$billingUnits = $_POST['item_billing_unit'] ?? [];
$travelRule = mileage_rule_from_post($_POST, ['rate'=>(float)($appConfig['default_mileage_rate']??0.670),'included'=>(float)($appConfig['default_mileage_included_miles']??0)]);

// Validate client_id
if ($client_id <= 0) {
    header('Location: /?page=quote/quotes-create&error=Please%20select%20a%20client');
    exit;
}
if ($project_id && !pa_project_is_active_for_client($pdo, $project_id, $client_id, (int)($_SESSION['user']['id'] ?? 0))) {
    header('Location: /?page=quote/quotes-create&error=' . urlencode('Select an active or not-started project for this client or organization.'));
    exit;
}
$__orgId = resolve_client_context_org_id($pdo, $client_id, $project_id, $__orgId);

// Items are required for regular quotes, on-demand itemized quotes, and long-term fixed totals.
// Items are optional for long-term per-invoice pricing and on-demand flat pricing.
// On-demand 'items' mode is also allowed to fall back to a flat amount when no line items are entered.
$od_flat_amount = max(0.0, (float)($_POST['od_flat_amount'] ?? 0));
$requires_items = !($is_long_term && $pricing_type === 'per_invoice')
               && !($is_on_demand && ($od_pricing_mode === 'flat' || $od_flat_amount > 0));

if ($requires_items && empty($item)) {
    header('Location: /?page=quote/quotes-create&error=Add%20at%20least%20one%20item');
    exit;
}

$items = [];
$subtotal = 0.0;

// For long-term quotes with per_invoice pricing, use the price_per_invoice value
if ($is_long_term && $pricing_type === 'per_invoice') {
    $subtotal = $price_per_invoice;
} elseif ($is_on_demand && $od_pricing_mode === 'flat') {
    $subtotal = $od_flat_amount;
    if ($subtotal <= 0) {
        header('Location: /?page=quote/quotes-create&error=Enter%20a%20flat%20quote%20amount');
        exit;
    }
} elseif ($is_on_demand) {
    // On-demand with od_pricing_mode='items': first process any line items.
    for ($i = 0; $i < count($item); $i++) {
        $itm = trim((string)($item[$i] ?? ''));
        $d = trim((string)($desc[$i] ?? ''));
        $q = (float)($qty[$i] ?? 0);
        $p = (float)($price[$i] ?? 0);
        if ($itm === '' || $q <= 0 || $p < 0) continue;
        $line = $q * $p;
        $subtotal += $line;
        $unit = (($billingUnits[$i] ?? 'each') === 'hour' || $billing_mode === 'hourly') ? 'hour' : 'each';
        $items[] = ['item' => $itm, 'description' => $d, 'quantity' => $q, 'unit_price' => $p, 'line_total' => $line, 'billing_unit' => $unit];
    }
    // Fallback: if no valid line items were entered, use the flat amount if provided.
    if (!$items) {
        if ($od_flat_amount > 0) {
            $subtotal = $od_flat_amount;
        }
    }
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
        $unit = (($billingUnits[$i] ?? 'each') === 'hour' || $billing_mode === 'hourly') ? 'hour' : 'each';
        $items[] = ['item' => $itm, 'description' => $d, 'quantity' => $q, 'unit_price' => $p, 'line_total' => $line, 'billing_unit' => $unit];
    }
    if (!$items) {
        header('Location: /?page=quote/quotes-create&error=Add%20at%20least%20one%20item');
        exit;
    }
}

$travelItem=mileage_document_travel_item($travelRule);
if($travelItem&&$travelItem['pricing_status']!=='variable')$subtotal+=(float)$travelItem['line_total'];

$discount_amount = 0.0;
if ($discount_type === 'percent') {
    $discount_amount = max(0.0, min(100.0, $discount_value)) * $subtotal / 100.0;
} elseif ($discount_type === 'fixed') {
    $discount_amount = max(0.0, $discount_value);
}
$tax_amount = max(0.0, $tax_percent) * max(0.0, $subtotal - $discount_amount) / 100.0;
$total = max(0.0, $subtotal - $discount_amount + $tax_amount);

if ($is_on_demand) {
    $price_per_invoice = $subtotal;
}

// Extract custom field values from POST data (only non-empty values)
$customFields = extractCustomFieldValues($_POST);
$customFieldsJson = !empty($customFields) ? json_encode($customFields) : null;

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO quotes (client_id, project_id, doc_number, project_code, status, quote_type, billing_mode, discount_type, discount_value, tax_percent, subtotal, total, deposit_type, deposit_amount, fulfillment_date, start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice, scope, custom_fields, organization_id, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$client_id, $project_id, null, null, 'pending', $quote_type, $billing_mode, $discount_type, $discount_value, $tax_percent, $subtotal, $total, $deposit_type, $deposit_value, $fulfillment_date, $start_date, $end_date, $billing_interval_count, $billing_interval_unit, $pricing_type, $price_per_invoice, $scope, $customFieldsJson, $__orgId, $__creator, date("Y-m-d H:i:s")]);
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
        $qi = $pdo->prepare('INSERT INTO quote_items (quote_id, item, description, quantity, unit_price, line_total, billing_unit) VALUES (?,?,?,?,?,?,?)');
        foreach ($items as $it) {
            $qi->execute([$quote_id, $it['item'], $it['description'], $it['quantity'], $it['unit_price'], $it['line_total'], $it['billing_unit'] ?? 'each']);
        }
    }
    if($travelItem){
        $pdo->prepare('INSERT INTO quote_items (quote_id,item,description,quantity,unit_price,line_total,billing_unit,is_travel,pricing_status) VALUES (?,?,?,?,?,?,?,1,?)')->execute([$quote_id,$travelItem['item'],$travelItem['description'],$travelItem['quantity'],$travelItem['unit_price'],$travelItem['line_total'],$travelItem['billing_unit'],$travelItem['pricing_status']]);
    }
    mileage_save_document_rule($pdo,'quote',$quote_id,$__orgId,$client_id,(int)$__creator,$travelRule);

    // Add to project_documents if project_id is set
    if ($project_id) {
        $pdo->prepare('INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?, "quote", ?)')->execute([$project_id, $quote_id]);
    }

    audit_log($pdo, 'quote.create', 'quote', $quote_id, ['client_id' => $client_id, 'organization_id' => $__orgId, 'created_by' => $__creator]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();

    error_log($e);

    header('Location: /?page=quote/quotes-create&error=Failed%20to%20create%20quote');
    exit;
}

// Redirect to the appropriate list based on quote type
if ($return_to_project > 0) {
    header('Location: /?page=project/projects-details&id=' . $return_to_project . '&created=quote');
} elseif ($quote_type === 'long_term') {
    header('Location: /?page=quote/long-term-quotes-list&created=1');
} elseif ($quote_type === 'on_demand') {
    header('Location: /?page=quote/on-demand-quotes-list&created=1');
} else {
    header('Location: /?page=quote/quotes-list&created=1');
}
exit;
