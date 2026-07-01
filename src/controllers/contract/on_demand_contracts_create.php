<?php
// src/controllers/contract/on_demand_contracts_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/project_id.php';
require_once __DIR__ . '/../../utils/project_selection.php';

@error_log('[on_demand_contracts_create] POST received', 0);

$client_id = (int)($_POST['client_id'] ?? 0);
$project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
$return_to_project = (int)($_POST['return_to_project'] ?? 0);
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$billing_mode = ($_POST['billing_mode'] ?? 'fixed') === 'hourly' ? 'hourly' : 'fixed';
$deposit_type = in_array(($_POST['deposit_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['deposit_type'] : 'none';
$deposit_value = (float)($_POST['deposit_value'] ?? 0);

// On-demand contract specific fields - accepts both prefixed (od_) and non-prefixed field names
$start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : (!empty($_POST['od_start_date']) ? $_POST['od_start_date'] : date('Y-m-d'));
$end_date = null; // On-demand contracts are always ongoing until terminated
$billing_interval_count = 1;
$billing_interval_unit = 'month';

// Price can come from a flat amount or line items.
$od_pricing_mode = in_array(($_POST['od_pricing_mode'] ?? 'items'), ['items', 'flat'], true) ? $_POST['od_pricing_mode'] : 'items';
$price_per_invoice = max(0.0, (float)($_POST['od_flat_amount'] ?? $_POST['price_per_invoice'] ?? 0));
$scope = trim((string)($_POST['scope'] ?? ''));

if ($client_id <= 0) {
    $client_name = trim((string)($_POST['client'] ?? ''));
    if ($client_name !== '') {
        try {
            $st = $pdo->prepare('SELECT id FROM clients WHERE name = ? LIMIT 1');
            $st->execute([$client_name]);
            $cid = (int)$st->fetchColumn();
            if ($cid <= 0) {
                $st = $pdo->prepare('SELECT id FROM clients WHERE name LIKE ? ORDER BY name LIMIT 1');
                $st->execute(['%'.$client_name.'%']);
                $cid = (int)$st->fetchColumn();
            }
            if ($cid > 0) { $client_id = $cid; }
        } catch (Throwable $e) { @error_log('[on_demand_contracts_create] resolve by name failed: '.$e->getMessage(), 0); }
    }
}

if ($client_id <= 0) {
    @error_log('[on_demand_contracts_create] invalid client_id', 0);
    header('Location: /?page=contract/contracts-create&error=Please%20select%20a%20client');
    exit;
}
if ($project_id && !pa_project_is_active_for_client($pdo, $project_id, $client_id, (int)($_SESSION['user']['id'] ?? 0))) {
    header('Location: /?page=contract/contracts-create&error=' . urlencode('Select an active project for this client or organization.'));
    exit;
}

// On-demand contracts can have line items OR a flat price - check if we have either.
$hasLineItems = $od_pricing_mode === 'items'
    && !empty($_POST['item'])
    && is_array($_POST['item'])
    && count(array_filter($_POST['item'], static fn($value) => trim((string)$value) !== '')) > 0;
if ($od_pricing_mode === 'flat' && $price_per_invoice <= 0) {
    header('Location: /?page=contract/contracts-create&error=Enter%20a%20flat%20contract%20amount');
    exit;
}
if (!$hasLineItems && $price_per_invoice <= 0) {
    header('Location: /?page=contract/contracts-create&error=Please%20add%20items%20or%20enter%20a%20flat%20amount');
    exit;
}

// Calculate subtotal from line items or flat price
$items = [];
$subtotal = 0.0;

if ($hasLineItems) {
    $item = $_POST['item'] ?? [];
    $desc = $_POST['item_desc'] ?? [];
    $qty = $_POST['item_qty'] ?? [];
    $price = $_POST['item_price'] ?? [];
    $billingUnits = $_POST['item_billing_unit'] ?? [];
    
    for ($i = 0; $i < count($item); $i++) {
        $itm = trim((string)($item[$i] ?? ''));
        $d = trim((string)($desc[$i] ?? ''));
        $q = (float)($qty[$i] ?? 0);
        $p = (float)($price[$i] ?? 0);
        if ($itm === '' || $q <= 0) continue;
        $line = $q * $p;
        $subtotal += $line;
        $unit = (($billingUnits[$i] ?? 'each') === 'hour' || $billing_mode === 'hourly') ? 'hour' : 'each';
        $items[] = ['i' => $itm, 'd' => $d, 'q' => $q, 'p' => $p, 't' => $line, 'u' => $unit];
    }
}

// Fallback: if no valid line items were entered, use the flat amount if provided.
if (empty($items)) {
    $subtotal = $price_per_invoice;
} else {
    $price_per_invoice = $subtotal;
}

$discount_amount = 0.0; 
if($discount_type === 'percent'){ 
    $discount_amount = max(0, min(100, $discount_value)) * $subtotal / 100; 
} elseif($discount_type === 'fixed'){ 
    $discount_amount = max(0, $discount_value); 
}

$tax = max(0, $tax_percent) * max(0, $subtotal - $discount_amount) / 100;
$total = max(0, $subtotal - $discount_amount + $tax);

// Calculate deposit amount
$deposit_amount = 0.0;
if($deposit_type === 'percent') { 
    $deposit_amount = max(0, min(100, $deposit_value)) * $total / 100; 
}
elseif($deposit_type === 'fixed') { 
    $deposit_amount = max(0, $deposit_value); 
}

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$sessionUserId = (int)($_SESSION['user']['id'] ?? 0) ?: 1;
$activeOrgId   = (int)(get_active_org_id() ?: 0);
if (!$activeOrgId) {
    $activeOrgId = (int)($pdo->query('SELECT id FROM organizations ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);
}

$pdo->beginTransaction();
try{
    // Get project code
    $projectCode = 'PA-'.date('Y').'-001';
    try { 
        $projectCode = project_next_code($pdo, $client_id); 
    } catch (Throwable $e) { 
        @error_log('[on_demand_contracts_create] project_next_code failed: '.$e->getMessage(), 0); 
    }

    // Insert on-demand contract into the unified contracts table
    $sql = 'INSERT INTO contracts (
        client_id, project_id, project_code, status, contract_type, billing_mode, start_date, end_date,
        billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice,
        discount_type, discount_value, tax_percent, subtotal, total,
        deposit_type, deposit_amount, deposit_paid,
        total_invoiced, invoice_count, scope, organization_id, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    
    $pdo->prepare($sql)->execute([
        $client_id, $project_id, $projectCode, 'pending', 'on_demand', $billing_mode, $start_date, $end_date,
        $billing_interval_count, $billing_interval_unit, 'on_demand', $price_per_invoice,
        $discount_type, $discount_value, $tax_percent, $subtotal, $total,
        $deposit_type, $deposit_amount, 0,
        0, 0, $scope, $activeOrgId, $sessionUserId
    ]);
    
    $contract_id = (int)$pdo->lastInsertId();

    // Assign doc number
    $maxDoc = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM contracts WHERE contract_type = "on_demand"')->fetchColumn();
    $pdo->prepare('UPDATE contracts SET doc_number=? WHERE id=?')->execute([$maxDoc + 1, $contract_id]);

    // Save line items if we have them
    if (!empty($items)) {
        $ins = $pdo->prepare('INSERT INTO contract_items (contract_id, item, description, quantity, unit_price, line_total, billing_unit) VALUES (?,?,?,?,?,?,?)');
        foreach ($items as $it) {
            $ins->execute([$contract_id, $it['i'], $it['d'], $it['q'], $it['p'], $it['t'], $it['u']]);
        }
    }

    // Save project notes
    $notes = trim((string)($_POST['project_notes'] ?? ''));
    if ($notes !== '') {
        try {
            $up = $pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes) VALUES (?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes)');
            $up->execute([$projectCode, $client_id, $notes]);
        } catch (Throwable $e) {
            @error_log('[on_demand_contracts_create] project_meta upsert failed: '.$e->getMessage(), 0);
        }
    }

    // Save contract signatures
    $signatureTitles = $_POST['signature_titles'] ?? [];
    $signatureOrders = $_POST['signature_orders'] ?? [];
    $signatureRequired = $_POST['signature_required'] ?? [];
    
    if (!empty($signatureTitles)) {
        $sigStmt = $pdo->prepare('INSERT INTO contract_signatures (contract_id, signatory_type) VALUES (?, ?)');
        foreach ($signatureTitles as $idx => $title) {
            $title = trim($title);
            if (empty($title)) continue;

            $sigStmt->execute([$contract_id, $idx === 0 ? 'client' : 'witness']);
        }
    }

    // Add to project_documents if project_id is set
    if ($project_id) {
        $pdo->prepare('INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?, "contract", ?)')->execute([$project_id, $contract_id]);
    }

    $pdo->commit();
} catch(Throwable $e){
    if ($pdo->inTransaction()) $pdo->rollBack();
    @error_log('[on_demand_contracts_create] exception: '.$e->getMessage(), 0);
    $msg = substr($e->getMessage(), 0, 200);
    header('Location: /?page=contract/contracts-create&error=' . urlencode($msg));
    exit;
}

if ($return_to_project > 0) {
    header('Location: /?page=project/projects-details&id=' . $return_to_project . '&created=contract');
} else {
    header('Location: /?page=contract/on-demand-contracts-list&created=1');
}
exit;
