<?php
// src/controllers/quotes_update.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/document_fields.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/acl_middleware.php';
require_once __DIR__ . '/../../utils/mileage.php';
require_once __DIR__ . '/../../utils/document_locations.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/DocumentPolicy.php';
require_once __DIR__ . '/../../services/DocumentRevisionService.php';
require_once __DIR__ . '/../../services/ScheduleService.php';
$id = (int)($_POST['id'] ?? 0);
require_record_ownership($pdo, 'quotes', $id);
try { $existingQuote=DocumentPolicy::assertMutable($pdo,'quote',$id); } catch(DocumentLockedException $locked){http_response_code(409);header('Content-Type: application/json');echo json_encode(['success'=>false,'code'=>'document_locked','message'=>$locked->getMessage(),'request_id'=>bin2hex(random_bytes(8))]);exit;}
$client_id = (int)($_POST['client_id'] ?? 0);
$requestedServiceLocationId = !empty($_POST['service_location_id']) ? (int)$_POST['service_location_id'] : null;
if (!empty($existingQuote['job_id']) && $client_id !== (int)$existingQuote['client_id']) {
  http_response_code(409);header('Content-Type: application/json');echo json_encode(['success'=>false,'code'=>'job_client_conflict','message'=>'A document cannot be moved to another client while it belongs to a Job. Clone it into a new Job instead.','request_id'=>bin2hex(random_bytes(8))]);exit;
}
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
$travelRule=mileage_rule_from_post($_POST,['rate'=>(float)($appConfig['default_mileage_rate']??0.670),'included'=>(float)($appConfig['default_mileage_included_miles']??0)]);
if ($id<=0 || $client_id<=0) { header('Location: /?page=quote/quotes-list&error=Invalid'); exit; }
$items=[];$subtotal=0.0;
for($i=0;$i<count($item);$i++){
  $itm=trim((string)($item[$i]??'')); $d=trim((string)($desc[$i]??'')); $q=(float)($qty[$i]??0); $p=(float)($price[$i]??0);
  if($itm===''||$q<=0||$p<0) continue; $line=$q*$p; $subtotal+=$line; $unit=(($billingUnits[$i]??'each')==='hour'||$billing_mode==='hourly')?'hour':'each'; $items[]=['i'=>$itm,'d'=>$d,'q'=>$q,'p'=>$p,'t'=>$line,'u'=>$unit];
}
$travelItem=mileage_document_travel_item($travelRule);if($travelItem&&$travelItem['pricing_status']!=='variable')$subtotal+=(float)$travelItem['line_total'];
$discount_amount=0.0; if($discount_type==='percent'){$discount_amount=max(0,min(100,$discount_value))*$subtotal/100;} elseif($discount_type==='fixed'){$discount_amount=max(0,$discount_value);} $tax=max(0,$tax_percent)*max(0,$subtotal-$discount_amount)/100; $total=max(0,$subtotal-$discount_amount+$tax);
// Extract custom field values from POST
$customFieldValues = extractCustomFieldValues($_POST);
$customFieldsJson = !empty($customFieldValues) ? json_encode($customFieldValues) : null;

$pdo->beginTransaction();
try{
  $serviceLocationId = document_resolve_service_location($pdo,$client_id,!empty($existingQuote['project_id'])?(int)$existingQuote['project_id']:null,!empty($existingQuote['job_id'])?(int)$existingQuote['job_id']:null,$requestedServiceLocationId);
  $pdo->prepare('UPDATE quotes SET client_id=?, billing_mode=?, discount_type=?, discount_value=?, tax_percent=?, subtotal=?, total=?, deposit_type=?, deposit_amount=?, fulfillment_date=?, scope=?, custom_fields=?, service_location_id=? WHERE id=?')->execute([$client_id,$billing_mode,$discount_type,$discount_value,$tax_percent,$subtotal,$total,$deposit_type,$deposit_value,$fulfillment_date,$scope,$customFieldsJson,$serviceLocationId,$id]);
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
  if($travelItem)$pdo->prepare('INSERT INTO quote_items (quote_id,item,description,quantity,unit_price,line_total,billing_unit,is_travel,pricing_status) VALUES (?,?,?,?,?,?,?,1,?)')->execute([$id,$travelItem['item'],$travelItem['description'],$travelItem['quantity'],$travelItem['unit_price'],$travelItem['line_total'],$travelItem['billing_unit'],$travelItem['pricing_status']]);
  $quoteOrg=$pdo->prepare('SELECT organization_id FROM quotes WHERE id=?');$quoteOrg->execute([$id]);mileage_save_document_rule($pdo,'quote',$id,($quoteOrg->fetchColumn()?:null),$client_id,(int)($_SESSION['user']['id']??0),$travelRule);
  DocumentRevisionService::snapshotAndSave($pdo,'quote',$id,(int)($_SESSION['user']['id']??0));
  if(!empty($existingQuote['job_id']))ScheduleService::syncJob($pdo,(int)$existingQuote['job_id'],(string)($appConfig['timezone']??'UTC'),(int)($_SESSION['user']['id']??0));
  $pdo->commit();
}catch(Throwable $e){ $pdo->rollBack(); header('Location: /?page=quote/quote-details&id=' . $id . '&error=Update%20failed'); exit; }
header('Location: /?page=quote/quote-details&id=' . $id . '&updated=1');
exit;
