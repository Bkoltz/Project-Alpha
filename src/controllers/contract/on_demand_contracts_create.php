<?php
// src/controllers/contract/on_demand_contracts_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/project_id.php';

@error_log('[on_demand_contracts_create] POST received', 0);

$client_id = (int)($_POST['client_id'] ?? 0);
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$deposit_type = in_array(($_POST['deposit_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['deposit_type'] : 'none';
$deposit_value = (float)($_POST['deposit_value'] ?? 0);

// On-demand contract specific fields
$start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
$end_date_type = $_POST['end_date_type'] ?? 'ongoing';
$end_date = ($end_date_type === 'fixed' && !empty($_POST['end_date'])) ? $_POST['end_date'] : null;
$billing_interval_count = max(1, (int)($_POST['billing_interval_count'] ?? 1));
$billing_interval_unit = in_array(($_POST['billing_interval_unit'] ?? 'month'), ['day','week','month','year']) ? $_POST['billing_interval_unit'] : 'month';
$price_per_invoice = (float)($_POST['price_per_invoice'] ?? 0);
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

if (!$start_date) {
    header('Location: /?page=contract/contracts-create&error=Start%20date%20is%20required%20for%20on-demand%20contracts');
    exit;
}

if ($price_per_invoice <= 0) {
    header('Location: /?page=contract/contracts-create&error=Price%20per%20invoice%20must%20be%20greater%20than%200');
    exit;
}

// Calculate subtotal (same as price per invoice for on-demand)
$subtotal = $price_per_invoice;

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

    // Insert on-demand contract
    $sql = 'INSERT INTO on_demand_contracts (
        client_id, project_code, status, start_date, end_date, 
        billing_interval_count, billing_interval_unit, price_per_invoice,
        discount_type, discount_value, tax_percent, subtotal,
        deposit_type, deposit_amount, deposit_paid,
        total_invoiced, invoice_count, scope
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    
    $pdo->prepare($sql)->execute([
        $client_id, $projectCode, 'pending', $start_date, $end_date,
        $billing_interval_count, $billing_interval_unit, $price_per_invoice,
        $discount_type, $discount_value, $tax_percent, $subtotal,
        $deposit_type, $deposit_amount, 0,
        0, 0, $scope
    ]);
    
    $odc_id = (int)$pdo->lastInsertId();

    // Assign doc number
    $maxDoc = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM on_demand_contracts')->fetchColumn();
    $pdo->prepare('UPDATE on_demand_contracts SET doc_number=? WHERE id=?')->execute([$maxDoc + 1, $odc_id]);

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
