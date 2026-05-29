<?php
// src/controllers/contract/on_demand_contracts_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/project_id.php';

@error_log('[on_demand_contracts_create] POST received', 0);

$client_id = (int)($_POST['client_id'] ?? 0);
$project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$deposit_type = in_array(($_POST['deposit_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['deposit_type'] : 'none';
$deposit_value = (float)($_POST['deposit_value'] ?? 0);

// On-demand contract specific fields - accepts both prefixed (od_) and non-prefixed field names
$start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : (!empty($_POST['od_start_date']) ? $_POST['od_start_date'] : date('Y-m-d'));
$end_date = null; // On-demand contracts are always ongoing until terminated
$billing_interval_count = 1;
$billing_interval_unit = 'month';

// Price can come from flat amount or line items
$price_per_invoice = (float)($_POST['price_per_invoice'] ?? $_POST['od_flat_amount'] ?? 0);
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

// On-demand contracts can have line items OR a flat price - check if we have either
$hasLineItems = !empty($_POST['item']) && is_array($_POST['item']) && count(array_filter($_POST['item'], 'trim')) > 0;
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
    
    for ($i = 0; $i < count($item); $i++) {
        $itm = trim((string)($item[$i] ?? ''));
        $d = trim((string)($desc[$i] ?? ''));
        $q = (float)($qty[$i] ?? 0);
        $p = (float)($price[$i] ?? 0);
        if ($itm === '' || $q <= 0) continue;
        $line = $q * $p;
        $subtotal += $line;
        $items[] = ['i' => $itm, 'd' => $d, 'q' => $q, 'p' => $p, 't' => $line];
    }
} else {
    $subtotal = $price_per_invoice;
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
        client_id, project_id, project_code, status, contract_type, start_date, end_date, 
        billing_interval_count, billing_interval_unit, price_per_invoice,
        discount_type, discount_value, tax_percent, subtotal,
        deposit_type, deposit_amount, deposit_paid,
        total_invoiced, invoice_count, scope
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    
    $pdo->prepare($sql)->execute([
        $client_id, $project_id, $projectCode, 'pending', 'on_demand', $start_date, $end_date,
        $billing_interval_count, $billing_interval_unit, $price_per_invoice,
        $discount_type, $discount_value, $tax_percent, $subtotal,
        $deposit_type, $deposit_amount, 0,
        0, 0, $scope
    ]);
    
    $contract_id = (int)$pdo->lastInsertId();

    // Assign doc number
    $maxDoc = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM contracts WHERE contract_type = "on_demand"')->fetchColumn();
    $pdo->prepare('UPDATE contracts SET doc_number=? WHERE id=?')->execute([$maxDoc + 1, $contract_id]);

    // Save line items if we have them
    if (!empty($items)) {
        $ins = $pdo->prepare('INSERT INTO contract_items (contract_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');
        foreach ($items as $it) {
            $ins->execute([$contract_id, $it['i'], $it['d'], $it['q'], $it['p'], $it['t']]);
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

header('Location: /?page=contract/on-demand-contracts-list&created=1');
exit;
