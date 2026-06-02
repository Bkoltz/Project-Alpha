<?php
// src/controllers/contracts_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/project_id.php';
require_once __DIR__ . '/../../utils/document_fields.php';

// Route to appropriate handler based on document type
$doc_type = $_POST['doc_type'] ?? 'regular';
if ($doc_type === 'long_term') {
    require __DIR__ . '/long_term_contracts_create.php';
    exit;
}
if ($doc_type === 'on_demand') {
    require __DIR__ . '/on_demand_contracts_create.php';
    exit;
}

// Regular contract creation continues below
@error_log('[contracts_create] POST received - regular contract', 0);

$client_id = (int)($_POST['client_id'] ?? 0);
$project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
// Check both direct field names and custom_field_ prefixed names (from dynamic rendering)
$deposit_type = $_POST['deposit_type'] ?? $_POST['custom_field_deposit_type'] ?? 'none';
$deposit_type = in_array($deposit_type, ['none','percent','fixed']) ? $deposit_type : 'none';
$deposit_value = (float)($_POST['deposit_value'] ?? $_POST['custom_field_deposit_value'] ?? 0);
$fulfillment_date = $_POST['fulfillment_date'] ?? $_POST['custom_field_fulfillment_date'] ?? null;
$fulfillment_date = !empty($fulfillment_date) ? $fulfillment_date : null;
$item = $_POST['item'] ?? [];
$desc = $_POST['item_desc'] ?? [];
$qty = $_POST['item_qty'] ?? [];
$price = $_POST['item_price'] ?? [];

if ($client_id <= 0) {
    // Fallback: try resolving by posted client name
    $client_name = trim((string)($_POST['client'] ?? ''));
    if ($client_name !== '') {
        try {
            // First, exact (case-insensitive), then LIKE
            $st = $pdo->prepare('SELECT id FROM clients WHERE name = ? LIMIT 1');
            $st->execute([$client_name]);
            $cid = (int)$st->fetchColumn();
            if ($cid <= 0) {
                $st = $pdo->prepare('SELECT id FROM clients WHERE name LIKE ? ORDER BY name LIMIT 1');
                $st->execute(['%'.$client_name.'%']);
                $cid = (int)$st->fetchColumn();
            }
            if ($cid > 0) { $client_id = $cid; }
        } catch (Throwable $e) { @error_log('[contracts_create] resolve by name failed: '.$e->getMessage(), 0); }
    }
}
if ($client_id <= 0) {
    @error_log('[contracts_create] invalid client_id', 0);
    header('Location: /?page=contract/contracts-create&error=Please%20select%20a%20client%20from%20suggestions');
    exit;
}

$items=[];$subtotal=0.0;
for($i=0;$i<count($item);$i++){
  $itm=trim((string)($item[$i]??'')); $d=trim((string)($desc[$i]??'')); $q=(float)($qty[$i]??0); $p=(float)($price[$i]??0);
  if($itm===''||$q<=0||$p<0) continue; $line=$q*$p; $subtotal+=$line; $items[]=['i'=>$itm,'d'=>$d,'q'=>$q,'p'=>$p,'t'=>$line];
}
if(!$items){
    @error_log('[contracts_create] no valid items', 0);
    header('Location: /?page=contract/contracts-create&error=Add%20at%20least%20one%20item');
    exit;
}
$discount_amount=0.0; if($discount_type==='percent'){ $discount_amount = max(0,min(100,$discount_value))*$subtotal/100; } elseif($discount_type==='fixed'){ $discount_amount = max(0,$discount_value); }
$tax = max(0,$tax_percent)*max(0,$subtotal-$discount_amount)/100; $total=max(0,$subtotal-$discount_amount+$tax);

// Calculate deposit amount based on type
$deposit_amount = 0.0;
if($deposit_type === 'percent') { $deposit_amount = max(0, min(100, $deposit_value)) * $total / 100; }
elseif($deposit_type === 'fixed') { $deposit_amount = max(0, $deposit_value); }

// Invoice total is the full amount - deposits are tracked via payments table
$invoice_total = $total;

$memo = trim((string)($_POST['memo'] ?? '')) ?: null;

// Extract custom field values from POST data (only non-empty values)
$customFields = extractCustomFieldValues($_POST);
$customFieldsJson = !empty($customFields) ? json_encode($customFields) : null;

$pdo->beginTransaction();
try{
  $pdo->prepare('INSERT INTO contracts (quote_id, client_id, project_id, status, discount_type, discount_value, tax_percent, subtotal, total, deposit_type, deposit_amount, deposit_paid, fulfillment_date, memo, custom_fields) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([$client_id, $project_id, 'pending', $discount_type, $discount_value, $tax_percent, $subtotal, $total, $deposit_type, $deposit_amount, 0, $fulfillment_date, $memo, $customFieldsJson]);
  $co_id = (int)$pdo->lastInsertId();

  // Assign Project ID and doc number (fallback if unavailable)
  $projectCode = 'PA-'.date('Y').'-001';
  try { $projectCode = project_next_code($pdo, $client_id); } catch (Throwable $e) { @error_log('[contracts_create] project_next_code failed: '.$e->getMessage(), 0); }
  $pdo->prepare('UPDATE contracts SET project_code=? WHERE id=?')->execute([$projectCode, $co_id]);

  $notes = trim((string)($_POST['project_notes'] ?? ''));
  if ($notes !== '') {
    try {
      $up = $pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes) VALUES (?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes)');
      $up->execute([$projectCode, $client_id, $notes]);
    } catch (Throwable $e) {
      @error_log('[contracts_create] project_meta upsert failed: '.$e->getMessage(), 0);
    }
  }

  // Assign per-type doc_number for contracts
  $cMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM contracts')->fetchColumn();
  $pdo->prepare('UPDATE contracts SET doc_number=? WHERE id=?')->execute([$cMax + 1, $co_id]);

  // Save contract items
  $ins=$pdo->prepare('INSERT INTO contract_items (contract_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');
  foreach($items as $it){ $ins->execute([$co_id,$it['i'],$it['d'],$it['q'],$it['p'],$it['t']]); }

  // Auto-create an invoice for this contract (invoice total is balance after deposit)
  $dueDate = null;
  $pdo->prepare('INSERT INTO invoices (contract_id, quote_id, client_id, project_id, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, project_code, fulfillment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$co_id, null, $client_id, $project_id, $discount_type, $discount_value, $tax_percent, $subtotal, $invoice_total, 'unpaid', $dueDate, $projectCode, $fulfillment_date]);
  $invoice_id = (int)$pdo->lastInsertId();
  $ii=$pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');
  foreach($items as $it){ $ii->execute([$invoice_id,$it['i'],$it['d'],$it['q'],$it['p'],$it['t']]); }
  // Assign per-type doc_number for invoices
  $iMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices')->fetchColumn();
  $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$iMax + 1, $invoice_id]);

  // Save contract signatures
  $signatureTitles = $_POST['signature_titles'] ?? [];
  $signatureOrders = $_POST['signature_orders'] ?? [];
  $signatureRequired = $_POST['signature_required'] ?? [];
  
  if (!empty($signatureTitles)) {
      $sigStmt = $pdo->prepare('INSERT INTO contract_signatures (contract_id, signer_title, display_order, is_required) VALUES (?, ?, ?, ?)');
      foreach ($signatureTitles as $idx => $title) {
          $title = trim($title);
          if (empty($title)) continue;
          
          $order = (int)($signatureOrders[$idx] ?? ($idx + 1));
          $isRequired = in_array('sig_' . $idx, $signatureRequired) ? 1 : 0;
          
          $sigStmt->execute([$co_id, $title, $order, $isRequired]);
      }
  }

  // Add to project_documents if project_id is set
  if ($project_id) {
      $pdo->prepare('INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?, "contract", ?)')->execute([$project_id, $co_id]);
      $pdo->prepare('INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?, "invoice", ?)')->execute([$project_id, $invoice_id]);
  }

  $pdo->commit();
}catch(Throwable $e){
  if ($pdo->inTransaction()) $pdo->rollBack();
  @error_log('[contracts_create] exception: '.$e->getMessage(), 0);
  $msg = substr($e->getMessage(), 0, 200);
  header('Location: /?page=contract/contracts-create&error=' . urlencode($msg));
  exit;
}
header('Location: /?page=contract/contracts-list&created=1');
exit;
