<?php
// src/controllers/contract/long_term_contracts_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/project_id.php';

@error_log('[long_term_contracts_create] POST received', 0);

$client_id = (int)($_POST['client_id'] ?? 0);
$project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$deposit_type = in_array(($_POST['deposit_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['deposit_type'] : 'none';
$deposit_value = (float)($_POST['deposit_value'] ?? 0);

// Long-term contract specific fields - accept both prefixed and non-prefixed field names
$start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : (!empty($_POST['lt_start_date']) ? $_POST['lt_start_date'] : null);
$end_date_type = $_POST['end_date_type'] ?? $_POST['lt_end_date_type'] ?? 'ongoing';
// Accept both 'fixed' and 'specific_date' as indicators for a specific end date
$has_end_date = in_array($end_date_type, ['fixed', 'specific_date']);
$end_date = ($has_end_date && !empty($_POST['end_date'])) ? $_POST['end_date'] : (($has_end_date && !empty($_POST['lt_end_date'])) ? $_POST['lt_end_date'] : null);
$billing_interval_count = max(1, (int)($_POST['billing_interval_count'] ?? $_POST['lt_billing_interval_count'] ?? 1));
$billing_interval_unit_val = $_POST['billing_interval_unit'] ?? $_POST['lt_billing_interval_unit'] ?? 'month';
$billing_interval_unit = in_array($billing_interval_unit_val, ['day','week','month','year']) ? $billing_interval_unit_val : 'month';
$pricing_type_val = $_POST['pricing_type'] ?? $_POST['lt_pricing_type'] ?? 'per_invoice';
$pricing_type = in_array($pricing_type_val, ['fixed_total','per_invoice']) ? $pricing_type_val : 'per_invoice';
$price_per_invoice = ($pricing_type === 'per_invoice') ? (float)($_POST['price_per_invoice'] ?? $_POST['lt_price_per_invoice'] ?? 0) : null;
$invoice_count = ($pricing_type === 'fixed_total') ? max(1, (int)($_POST['invoice_count'] ?? 1)) : null;
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
        } catch (Throwable $e) { @error_log('[long_term_contracts_create] resolve by name failed: '.$e->getMessage(), 0); }
    }
}

if ($client_id <= 0) {
    @error_log('[long_term_contracts_create] invalid client_id', 0);
    header('Location: /?page=contract/contracts-create&error=Please%20select%20a%20client');
    exit;
}

if (!$start_date) {
    header('Location: /?page=contract/contracts-create&error=Start%20date%20is%20required%20for%20long-term%20contracts');
    exit;
}

$desc = $_POST['item_desc'] ?? [];
$qty = $_POST['item_qty'] ?? [];
$price = $_POST['item_price'] ?? [];

// Calculate subtotal and total based on pricing type
$items = [];
$subtotal = 0.0;

if ($pricing_type === 'fixed_total') {
    // Use line items
    for($i=0; $i<count($desc); $i++){
        $d = trim((string)($desc[$i]??'')); 
        $q = (float)($qty[$i]??0); 
        $p = (float)($price[$i]??0);
        if($d === '' || $q <= 0 || $p < 0) continue; 
        $line = $q * $p; 
        $subtotal += $line; 
        $items[] = ['d'=>$d, 'q'=>$q, 'p'=>$p, 't'=>$line];
    }
    if(!$items){
        header('Location: /?page=contract/contracts-create&error=Add%20at%20least%20one%20item%20for%20fixed%20total%20pricing');
        exit;
    }
} else {
    // Per invoice pricing - use price_per_invoice as subtotal
    if ($price_per_invoice <= 0) {
        header('Location: /?page=contract/contracts-create&error=Price%20per%20invoice%20must%20be%20greater%20than%200');
        exit;
    }
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

// Calculate deposit amount based on type
$deposit_amount = 0.0;
if($deposit_type === 'percent') { 
    $deposit_amount = max(0, min(100, $deposit_value)) * $total / 100; 
}
elseif($deposit_type === 'fixed') { 
    $deposit_amount = max(0, $deposit_value); 
}

// Calculate next invoice date
$next_invoice_date = $start_date;

$pdo->beginTransaction();
try{
    // Get project code
    $projectCode = 'PA-'.date('Y').'-001';
    try { 
        $projectCode = project_next_code($pdo, $client_id); 
    } catch (Throwable $e) { 
        @error_log('[long_term_contracts_create] project_next_code failed: '.$e->getMessage(), 0); 
    }

    // Insert long-term contract into the unified contracts table
    $sql = 'INSERT INTO contracts (
        client_id, project_id, project_code, status, contract_type, start_date, end_date, 
        billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice,
        discount_type, discount_value, tax_percent, subtotal, total,
        deposit_type, deposit_amount, deposit_paid, total_invoiced,
        next_invoice_date, invoice_count, invoices_generated, scope
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    
    $pdo->prepare($sql)->execute([
        $client_id, $project_id, $projectCode, 'pending', 'long_term', $start_date, $end_date,
        $billing_interval_count, $billing_interval_unit, $pricing_type, $price_per_invoice,
        $discount_type, $discount_value, $tax_percent, $subtotal, $total,
        $deposit_type, $deposit_amount, 0, 0,
        $next_invoice_date, $invoice_count, 0, $scope
    ]);
    
    $contract_id = (int)$pdo->lastInsertId();

    // Assign doc number
    $maxDoc = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM contracts WHERE contract_type = "long_term"')->fetchColumn();
    $pdo->prepare('UPDATE contracts SET doc_number=? WHERE id=?')->execute([$maxDoc + 1, $contract_id]);

    // Save items if fixed_total pricing
    if ($pricing_type === 'fixed_total' && $items) {
        $ins = $pdo->prepare('INSERT INTO contract_items (contract_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');
        foreach($items as $it){ 
            $ins->execute([$contract_id, $it['d'], $it['d'], $it['q'], $it['p'], $it['t']]); 
        }
    }

    // Save project notes
    $notes = trim((string)($_POST['project_notes'] ?? ''));
    if ($notes !== '') {
        try {
            $up = $pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes) VALUES (?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes)');
            $up->execute([$projectCode, $client_id, $notes]);
        } catch (Throwable $e) {
            @error_log('[long_term_contracts_create] project_meta upsert failed: '.$e->getMessage(), 0);
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
    @error_log('[long_term_contracts_create] exception: '.$e->getMessage(), 0);
    $msg = substr($e->getMessage(), 0, 200);
    header('Location: /?page=contract/contracts-create&error=' . urlencode($msg));
    exit;
}

header('Location: /?page=contract/long-term-contracts-list&created=1');
exit;
