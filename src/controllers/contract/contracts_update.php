<?php
// src/controllers/contracts_update.php
require_once __DIR__ . '/../../config/db.php';
$id = (int)($_POST['id'] ?? 0);
$client_id = (int)($_POST['client_id'] ?? 0);
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$deposit_type = in_array(($_POST['deposit_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['deposit_type'] : 'none';
$deposit_amount = (float)($_POST['deposit_amount'] ?? 0);
$deposit_paid = (float)($_POST['deposit_paid'] ?? 0);
$fulfillment_date = !empty($_POST['fulfillment_date']) ? $_POST['fulfillment_date'] : null;
$desc = $_POST['item_desc'] ?? [];
$qty = $_POST['item_qty'] ?? [];
$price = $_POST['item_price'] ?? [];
if ($id<=0 || $client_id<=0) { header('Location: /?page=contract/contracts-list&error=Invalid'); exit; }
$items=[];$subtotal=0.0;
for($i=0;$i<count($desc);$i++){
  $d=trim((string)($desc[$i]??'')); $q=(float)($qty[$i]??0); $p=(float)($price[$i]??0);
  if($d===''||$q<=0||$p<0) continue; $line=$q*$p; $subtotal+=$line; $items[]=['d'=>$d,'q'=>$q,'p'=>$p,'t'=>$line];
}
$discount_amount=0.0; if($discount_type==='percent'){$discount_amount=max(0,min(100,$discount_value))*$subtotal/100;} elseif($discount_type==='fixed'){$discount_amount=max(0,$discount_value);} $tax=max(0,$tax_percent)*max(0,$subtotal-$discount_amount)/100; $total=max(0,$subtotal-$discount_amount+$tax);
$terms = trim((string)($_POST['terms'] ?? '')) ?: null;
$estimated = trim((string)($_POST['estimated_completion'] ?? '')) ?: null;
$weather = isset($_POST['weather_pending']) ? 1 : 0;
$scope = trim((string)($_POST['scope'] ?? '')) ?: null;
$pdo->beginTransaction();
try{
  $pdo->prepare('UPDATE contracts SET client_id=?, discount_type=?, discount_value=?, tax_percent=?, subtotal=?, total=?, terms=?, estimated_completion=?, weather_pending=?, deposit_type=?, deposit_amount=?, deposit_paid=?, fulfillment_date=?, scope=? WHERE id=?')->execute([$client_id,$discount_type,$discount_value,$tax_percent,$subtotal,$total,$terms,$estimated,$weather,$deposit_type,$deposit_amount,$deposit_paid,$fulfillment_date,$scope,$id]);
  
  // Sync changes to linked invoices
  $pdo->prepare('UPDATE invoices SET client_id=?, discount_type=?, discount_value=?, tax_percent=?, subtotal=?, total=?, estimated_completion=?, fulfillment_date=?, weather_pending=?, scope=? WHERE contract_id=?')->execute([$client_id,$discount_type,$discount_value,$tax_percent,$subtotal,$total,$estimated,$fulfillment_date,$weather,$scope,$id]);
  
  // Sync items to linked invoices
  $invoiceIds = $pdo->prepare('SELECT id FROM invoices WHERE contract_id=?');
  $invoiceIds->execute([$id]);
  foreach($invoiceIds->fetchAll(PDO::FETCH_COLUMN) as $invId) {
    $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id=?')->execute([$invId]);
    $insInv=$pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
    foreach($items as $it){ $insInv->execute([$invId,$it['d'],$it['q'],$it['p'],$it['t']]); }
  }
  $row = $pdo->prepare('SELECT project_code FROM contracts WHERE id=?');
  $row->execute([$id]);
  $pc = (string)$row->fetchColumn();
  $pn = trim((string)($_POST['project_notes'] ?? ''));
  $pt = trim((string)($_POST['project_terms'] ?? ''));
  if ($pc !== '') {
    $up = $pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes, terms) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes), terms=VALUES(terms)');
    $up->execute([$pc, $client_id, $pn !== '' ? $pn : null, $pt !== '' ? $pt : null]);
  }
  $pdo->prepare('DELETE FROM contract_items WHERE contract_id=?')->execute([$id]);
  $ins=$pdo->prepare('INSERT INTO contract_items (contract_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
  foreach($items as $it){ $ins->execute([$id,$it['d'],$it['q'],$it['p'],$it['t']]); }
  $pdo->commit();
}catch(Throwable $e){ $pdo->rollBack(); header('Location: /?page=contract/contracts-list&error=Update%20failed'); exit; }
header('Location: /?page=contract/contracts-list&updated=1');
exit;
