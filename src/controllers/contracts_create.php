<?php
// src/controllers/contracts_create.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/project_id.php';
$client_id = (int)($_POST['client_id'] ?? 0);
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$desc = $_POST['item_desc'] ?? [];
$qty = $_POST['item_qty'] ?? [];
$price = $_POST['item_price'] ?? [];
if ($client_id <= 0 || empty($desc)) { header('Location: /?page=contracts-create&error=Invalid%20input'); exit; }
$items=[];$subtotal=0.0;
for($i=0;$i<count($desc);$i++){
  $d=trim((string)($desc[$i]??'')); $q=(float)($qty[$i]??0); $p=(float)($price[$i]??0);
  if($d===''||$q<=0||$p<0) continue; $line=$q*$p; $subtotal+=$line; $items[]=['d'=>$d,'q'=>$q,'p'=>$p,'t'=>$line];
}
if(!$items){ header('Location: /?page=contracts-create&error=Add%20at%20least%20one%20item'); exit; }
$discount_amount=0.0; if($discount_type==='percent'){ $discount_amount = max(0,min(100,$discount_value))*$subtotal/100; } elseif($discount_type==='fixed'){ $discount_amount = max(0,$discount_value); }
$tax = max(0,$tax_percent)*max(0,$subtotal-$discount_amount)/100; $total=max(0,$subtotal-$discount_amount+$tax);
$pdo->beginTransaction();
try{
  $pdo->prepare('INSERT INTO contracts (quote_id, client_id, status, discount_type, discount_value, tax_percent, subtotal, total) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([$client_id, 'draft', $discount_type, $discount_value, $tax_percent, $subtotal, $total]);
  $co_id = (int)$pdo->lastInsertId();
  // Assign Project ID and doc number
  $projectCode = project_next_code($pdo, $client_id);
  $pdo->prepare('UPDATE contracts SET project_code=? WHERE id=?')->execute([$projectCode, $co_id]);
  $docMax = (int)$pdo->query('SELECT GREATEST(
      COALESCE((SELECT MAX(doc_number) FROM quotes),0),
      COALESCE((SELECT MAX(doc_number) FROM contracts),0),
      COALESCE((SELECT MAX(doc_number) FROM invoices),0)
    )')->fetchColumn();
  $pdo->prepare('UPDATE contracts SET doc_number=? WHERE id=?')->execute([$docMax+1, $co_id]);
  $ins=$pdo->prepare('INSERT INTO contract_items (contract_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
  foreach($items as $it){ $ins->execute([$co_id,$it['d'],$it['q'],$it['p'],$it['t']]); }
  $pdo->commit();
}catch(Throwable $e){ $pdo->rollBack(); header('Location: /?page=contracts-create&error=Failed%20to%20create%20contract'); exit; }
header('Location: /?page=contracts-list&created=1');
exit;
