<?php
// src/controllers/contracts_create.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/project_id.php';

// Simple debug log to help diagnose if this endpoint is being hit
@error_log('[contracts_create] POST received', 0);

$client_id = (int)($_POST['client_id'] ?? 0);
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
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
    header('Location: /?page=contracts-create&error=Please%20select%20a%20client%20from%20suggestions');
    exit;
}

$items=[];$subtotal=0.0;
for($i=0;$i<count($desc);$i++){
  $d=trim((string)($desc[$i]??'')); $q=(float)($qty[$i]??0); $p=(float)($price[$i]??0);
  if($d===''||$q<=0||$p<0) continue; $line=$q*$p; $subtotal+=$line; $items[]=['d'=>$d,'q'=>$q,'p'=>$p,'t'=>$line];
}
if(!$items){
    @error_log('[contracts_create] no valid items', 0);
    header('Location: /?page=contracts-create&error=Add%20at%20least%20one%20item');
    exit;
}
$discount_amount=0.0; if($discount_type==='percent'){ $discount_amount = max(0,min(100,$discount_value))*$subtotal/100; } elseif($discount_type==='fixed'){ $discount_amount = max(0,$discount_value); }
$tax = max(0,$tax_percent)*max(0,$subtotal-$discount_amount)/100; $total=max(0,$subtotal-$discount_amount+$tax);

$pdo->beginTransaction();
try{
  $pdo->prepare('INSERT INTO contracts (quote_id, client_id, status, discount_type, discount_value, tax_percent, subtotal, total) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([$client_id, 'pending', $discount_type, $discount_value, $tax_percent, $subtotal, $total]);
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
  $ins=$pdo->prepare('INSERT INTO contract_items (contract_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
  foreach($items as $it){ $ins->execute([$co_id,$it['d'],$it['q'],$it['p'],$it['t']]); }

  // Auto-create an invoice for this contract (no due date until completion)
  $dueDate = null;
  $pdo->prepare('INSERT INTO invoices (contract_id, quote_id, client_id, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, project_code) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$co_id, null, $client_id, $discount_type, $discount_value, $tax_percent, $subtotal, $total, 'unpaid', $dueDate, $projectCode]);
  $invoice_id = (int)$pdo->lastInsertId();
  $ii=$pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
  foreach($items as $it){ $ii->execute([$invoice_id,$it['d'],$it['q'],$it['p'],$it['t']]); }
  // Assign per-type doc_number for invoices
  $iMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices')->fetchColumn();
  $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$iMax + 1, $invoice_id]);

  $pdo->commit();
}catch(Throwable $e){
  if ($pdo->inTransaction()) $pdo->rollBack();
  @error_log('[contracts_create] exception: '.$e->getMessage(), 0);
  header('Location: /?page=contracts-create&error=Failed%20to%20create%20contract');
  exit;
}
header('Location: /?page=contracts-list&created=1');
exit;
