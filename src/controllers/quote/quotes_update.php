<?php
// src/controllers/quotes_update.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/document_fields.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/acl_middleware.php';
$id = (int)($_POST['id'] ?? 0);
require_record_ownership($pdo, 'quotes', $id);
$client_id = (int)($_POST['client_id'] ?? 0);
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$billing_mode = ($_POST['billing_mode'] ?? 'fixed') === 'hourly' ? 'hourly' : 'fixed';
$deposit_type = in_array(($_POST['deposit_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['deposit_type'] : 'none';
$deposit_value = (float)($_POST['deposit_value'] ?? 0);
$fulfillment_date = !empty($_POST['fulfillment_date']) ? $_POST['fulfillment_date'] : null;
$scope = trim((string)($_POST['scope'] ?? '')) ?: null;
$item = $_POST['item'] ?? [];
$desc = $_POST['item_desc'] ?? [];
$qty = $_POST['item_qty'] ?? [];
$price = $_POST['item_price'] ?? [];
$billingUnits = $_POST['item_billing_unit'] ?? [];
if ($id<=0 || $client_id<=0) { header('Location: /?page=quote/quotes-list&error=Invalid'); exit; }
$items=[];$subtotal=0.0;
for($i=0;$i<count($item);$i++){
  $itm=trim((string)($item[$i]??'')); $d=trim((string)($desc[$i]??'')); $q=(float)($qty[$i]??0); $p=(float)($price[$i]??0);
  if($itm===''||$q<=0||$p<0) continue; $line=$q*$p; $subtotal+=$line; $unit=(($billingUnits[$i]??'each')==='hour'||$billing_mode==='hourly')?'hour':'each'; $items[]=['i'=>$itm,'d'=>$d,'q'=>$q,'p'=>$p,'t'=>$line,'u'=>$unit];
}
$discount_amount=0.0; if($discount_type==='percent'){$discount_amount=max(0,min(100,$discount_value))*$subtotal/100;} elseif($discount_type==='fixed'){$discount_amount=max(0,$discount_value);} $tax=max(0,$tax_percent)*max(0,$subtotal-$discount_amount)/100; $total=max(0,$subtotal-$discount_amount+$tax);
// Extract custom field values from POST
$customFieldValues = extractCustomFieldValues($_POST);
$customFieldsJson = !empty($customFieldValues) ? json_encode($customFieldValues) : null;

$pdo->beginTransaction();
try{
  $pdo->prepare('UPDATE quotes SET client_id=?, billing_mode=?, discount_type=?, discount_value=?, tax_percent=?, subtotal=?, total=?, deposit_type=?, deposit_amount=?, fulfillment_date=?, scope=?, custom_fields=? WHERE id=?')->execute([$client_id,$billing_mode,$discount_type,$discount_value,$tax_percent,$subtotal,$total,$deposit_type,$deposit_value,$fulfillment_date,$scope,$customFieldsJson,$id]);
  // Upsert project notes if provided and project_code is known
  $row = $pdo->prepare('SELECT project_code FROM quotes WHERE id=?');
  $row->execute([$id]);
  $pc = (string)$row->fetchColumn();
  $pn = trim((string)($_POST['project_notes'] ?? ''));
  $pt = trim((string)($_POST['project_terms'] ?? ''));
  if ($pc !== '') {
    $up = $pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes, terms) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes), terms=VALUES(terms)');
    $up->execute([$pc, $client_id, $pn !== '' ? $pn : null, $pt !== '' ? $pt : null]);
  }
  $pdo->prepare('DELETE FROM quote_items WHERE quote_id=?')->execute([$id]);
  $ins=$pdo->prepare('INSERT INTO quote_items (quote_id, item, description, quantity, unit_price, line_total, billing_unit) VALUES (?,?,?,?,?,?,?)');
  foreach($items as $it){ $ins->execute([$id,$it['i'],$it['d'],$it['q'],$it['p'],$it['t'],$it['u']]); }
  $pdo->commit();
}catch(Throwable $e){ $pdo->rollBack(); header('Location: /?page=quote/quote-details&id=' . $id . '&error=Update%20failed'); exit; }
header('Location: /?page=quote/quote-details&id=' . $id . '&updated=1');
exit;
