<?php
// src/controllers/contracts_update.php
require_once __DIR__ . '/../config/db.php';
$id = (int)($_POST['id'] ?? 0);
$client_id = (int)($_POST['client_id'] ?? 0);
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$desc = $_POST['item_desc'] ?? [];
$qty = $_POST['item_qty'] ?? [];
$price = $_POST['item_price'] ?? [];
if ($id<=0 || $client_id<=0) { header('Location: /?page=contracts-list&error=Invalid'); exit; }
$items=[];$subtotal=0.0;
for($i=0;$i<count($desc);$i++){
  $d=trim((string)($desc[$i]??'')); $q=(float)($qty[$i]??0); $p=(float)($price[$i]??0);
  if($d===''||$q<=0||$p<0) continue; $line=$q*$p; $subtotal+=$line; $items[]=['d'=>$d,'q'=>$q,'p'=>$p,'t'=>$line];
}
$discount_amount=0.0; if($discount_type==='percent'){$discount_amount=max(0,min(100,$discount_value))*$subtotal/100;} elseif($discount_type==='fixed'){$discount_amount=max(0,$discount_value);} $tax=max(0,$tax_percent)*max(0,$subtotal-$discount_amount)/100; $total=max(0,$subtotal-$discount_amount+$tax);
$terms = trim((string)($_POST['terms'] ?? '')) ?: null;
$estimated = trim((string)($_POST['estimated_completion'] ?? '')) ?: null;
$weather = isset($_POST['weather_pending']) ? 1 : 0;
$pdo->beginTransaction();
try{
  $pdo->prepare('UPDATE contracts SET client_id=?, discount_type=?, discount_value=?, tax_percent=?, subtotal=?, total=?, terms=?, estimated_completion=?, weather_pending=? WHERE id=?')->execute([$client_id,$discount_type,$discount_value,$tax_percent,$subtotal,$total,$terms,$estimated,$weather,$id]);
  $pdo->prepare('DELETE FROM contract_items WHERE contract_id=?')->execute([$id]);
  $ins=$pdo->prepare('INSERT INTO contract_items (contract_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
  foreach($items as $it){ $ins->execute([$id,$it['d'],$it['q'],$it['p'],$it['t']]); }
  $pdo->commit();
}catch(Throwable $e){ $pdo->rollBack(); header('Location: /?page=contracts-list&error=Update%20failed'); exit; }
header('Location: /?page=contracts-list&updated=1');
exit;
