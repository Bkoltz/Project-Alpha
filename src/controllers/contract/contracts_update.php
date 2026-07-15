<?php
// src/controllers/contracts_update.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/document_fields.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/acl_middleware.php';
require_once __DIR__ . '/../../utils/project_selection.php';
require_once __DIR__ . '/../../utils/contract_signatures.php';
require_once __DIR__ . '/../../utils/recurring_services.php';
require_once __DIR__ . '/../../utils/mileage.php';
require_once __DIR__ . '/../../utils/document_locations.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/DocumentPolicy.php';
require_once __DIR__ . '/../../services/DocumentRevisionService.php';
require_once __DIR__ . '/../../services/ScheduleService.php';
require_once __DIR__ . '/../../services/JobAssignmentService.php';
$id = (int)($_POST['id'] ?? 0);
require_record_ownership($pdo, 'contracts', $id);
$client_id = (int)($_POST['client_id'] ?? 0);
$project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
$requestedServiceLocationId = !empty($_POST['service_location_id']) ? (int)$_POST['service_location_id'] : null;
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$billing_mode = ($_POST['billing_mode'] ?? 'fixed') === 'hourly' ? 'hourly' : 'fixed';
$deposit_type = in_array(($_POST['deposit_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['deposit_type'] : 'none';
$deposit_amount = (float)($_POST['deposit_amount'] ?? 0);
$deposit_paid = (float)($_POST['deposit_paid'] ?? 0);
$fulfillment_date = !empty($_POST['fulfillment_date']) ? $_POST['fulfillment_date'] : null;
$item = $_POST['item'] ?? [];
$desc = $_POST['item_desc'] ?? [];
$qty = $_POST['item_qty'] ?? [];
$price = $_POST['item_price'] ?? [];
$billingUnits = $_POST['item_billing_unit'] ?? [];
$travelRule=mileage_rule_from_post($_POST,['rate'=>(float)($appConfig['default_mileage_rate']??0.670),'included'=>(float)($appConfig['default_mileage_included_miles']??0)]);
if ($id<=0 || $client_id<=0) { header('Location: /?page=contract/contracts-list&error=Invalid'); exit; }
$contractTypeStmt = $pdo->prepare('SELECT * FROM contracts WHERE id=? LIMIT 1');
$contractTypeStmt->execute([$id]);
$existingContract = $contractTypeStmt->fetch(PDO::FETCH_ASSOC);
try { $existingContract=DocumentPolicy::assertMutable($pdo,'contract',$id,'commercial'); } catch(DocumentLockedException $locked){http_response_code(409);header('Content-Type: application/json');echo json_encode(['success'=>false,'code'=>'document_locked','message'=>$locked->getMessage(),'request_id'=>bin2hex(random_bytes(8))]);exit;}
if (!empty($existingContract['job_id']) && $client_id !== (int)$existingContract['client_id']) {
  http_response_code(409);header('Content-Type: application/json');echo json_encode(['success'=>false,'code'=>'job_client_conflict','message'=>'A contract cannot be moved to another client while it belongs to a Job. Clone it into a new Job instead.','request_id'=>bin2hex(random_bytes(8))]);exit;
}
$contractType = (string)($existingContract['contract_type'] ?? '');
$isLongTermContract = $contractType === 'long_term';
$existingBaseService = null;
if ($isLongTermContract) {
  $baseServiceStmt = $pdo->prepare('SELECT * FROM contract_recurring_services WHERE contract_id=? AND is_base=1 ORDER BY id LIMIT 1');
  $baseServiceStmt->execute([$id]);
  $existingBaseService = $baseServiceStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$detailPage = $contractType === 'long_term' ? 'contract/long-term-contract-details' : 'contract/contract-details';
if ($project_id && !pa_project_is_active_for_client($pdo, $project_id, $client_id, (int)($_SESSION['user']['id'] ?? 0))) {
  header('Location: /?page=contract/contracts-edit&id=' . $id . '&error=' . urlencode('Select an active or not-started project for this client or organization.'));
  exit;
}
$items=[];$subtotal=0.0;
for($i=0;$i<count($item);$i++){
  $itm=trim((string)($item[$i]??'')); $d=trim((string)($desc[$i]??'')); $q=(float)($qty[$i]??0); $p=(float)($price[$i]??0);
  if($itm===''||$q<=0||$p<0) continue; $line=$q*$p; $subtotal+=$line; $requestedUnit=(string)($billingUnits[$i]??'each'); $unit=$billing_mode==='hourly'?'hour':(in_array($requestedUnit,['each','hour','mile'],true)?$requestedUnit:'each'); $items[]=['i'=>$itm,'d'=>$d,'q'=>$q,'p'=>$p,'t'=>$line,'u'=>$unit];
}

$longTerm = null;
if ($isLongTermContract) {
  $startDate = !empty($_POST['start_date']) ? (string)$_POST['start_date'] : null;
  $endDateType = (string)($_POST['end_date_type'] ?? 'ongoing');
  $endDate = $endDateType === 'fixed' && !empty($_POST['end_date']) ? (string)$_POST['end_date'] : null;
  $nextInvoiceDate = !empty($_POST['next_invoice_date']) ? (string)$_POST['next_invoice_date'] : null;
  $billingIntervalCount = max(1, (int)($_POST['billing_interval_count'] ?? 1));
  $billingIntervalUnit = (string)($_POST['billing_interval_unit'] ?? 'month');
  if (!in_array($billingIntervalUnit, ['day', 'week', 'month', 'year'], true)) {
    $billingIntervalUnit = 'month';
  }
  $pricingType = (string)($_POST['pricing_type'] ?? ($existingContract['pricing_type'] ?? 'per_invoice'));
  if (!in_array($pricingType, ['per_invoice', 'fixed_total'], true)) {
    $pricingType = 'per_invoice';
  }
  $billingStartMode = (string)($_POST['billing_start_mode'] ?? ($existingContract['billing_start_mode'] ?? 'on_upload'));
  if (!in_array($billingStartMode, ['on_upload', 'manual'], true)) {
    $billingStartMode = 'on_upload';
  }
  $pricePerInvoice = $pricingType === 'per_invoice' ? (float)($_POST['price_per_invoice'] ?? 0) : null;
  if ($pricingType === 'per_invoice') {
    if ($pricePerInvoice <= 0) {
      header('Location: /?page=' . $detailPage . '&id=' . $id . '&error=' . urlencode('Amount per invoice must be greater than 0'));
      exit;
    }
    $subtotal = $pricePerInvoice;
    $items = [];
  } elseif (!$items) {
    header('Location: /?page=' . $detailPage . '&id=' . $id . '&error=' . urlencode('Add at least one item for fixed total pricing'));
    exit;
  }
  $invoicesGenerated = max(0, (int)($existingContract['invoices_generated'] ?? 0));
  $invoiceCount = $pricingType === 'fixed_total'
    ? max(1, $invoicesGenerated, (int)($_POST['invoice_count'] ?? ($existingContract['invoice_count'] ?? 1)))
    : null;
  $longTerm = [
    'start_date' => $startDate,
    'end_date' => $endDate,
    'billing_interval_count' => $billingIntervalCount,
    'billing_interval_unit' => $billingIntervalUnit,
    'pricing_type' => $pricingType,
    'price_per_invoice' => $pricePerInvoice,
    'billing_start_mode' => $billingStartMode,
    'invoice_count' => $invoiceCount,
    'next_invoice_date' => $nextInvoiceDate,
  ];
}

$travelItem=mileage_document_travel_item($travelRule);if($travelItem&&$travelItem['pricing_status']!=='variable')$subtotal+=(float)$travelItem['line_total'];
$invoiceSubtotal=$subtotal-($travelItem&&$travelItem['pricing_status']==='estimate'?(float)$travelItem['line_total']:0);
$discount_amount=0.0; if($discount_type==='percent'){$discount_amount=max(0,min(100,$discount_value))*$subtotal/100;} elseif($discount_type==='fixed'){$discount_amount=max(0,$discount_value);} $tax=max(0,$tax_percent)*max(0,$subtotal-$discount_amount)/100; $total=max(0,$subtotal-$discount_amount+$tax);
$terms = trim((string)($_POST['terms'] ?? '')) ?: null;
$estimated = trim((string)($_POST['estimated_completion'] ?? '')) ?: null;
$weather = isset($_POST['weather_pending']) ? 1 : 0;
$scope = trim((string)($_POST['scope'] ?? '')) ?: null;
$memo = trim((string)($_POST['memo'] ?? '')) ?: null;
// Extract custom field values from POST
$customFieldValues = extractCustomFieldValues($_POST);
$customFieldsJson = !empty($customFieldValues) ? json_encode($customFieldValues) : null;

$pdo->beginTransaction();
try{
  if (!empty($existingContract['job_id']) && (int)($existingContract['project_id'] ?? 0) !== (int)($project_id ?? 0)) {
    JobAssignmentService::assignProject($pdo,(int)$existingContract['job_id'],$project_id);
  }
  $serviceLocationId = document_resolve_service_location($pdo,$client_id,$project_id,!empty($existingContract['job_id'])?(int)$existingContract['job_id']:null,$requestedServiceLocationId);
  if ($isLongTermContract && $longTerm) {
    $pdo->prepare('
      UPDATE contracts
      SET client_id=?, project_id=?, billing_mode=?, discount_type=?, discount_value=?, tax_percent=?,
          subtotal=?, total=?, terms=?, estimated_completion=?, weather_pending=?, deposit_type=?,
          deposit_amount=?, deposit_paid=?, fulfillment_date=?, scope=?, memo=?, custom_fields=?,
          start_date=?, end_date=?, billing_interval_count=?, billing_interval_unit=?, pricing_type=?,
          price_per_invoice=?, billing_start_mode=?, invoice_count=?, next_invoice_date=?, service_location_id=?
      WHERE id=?
    ')->execute([
      $client_id,$project_id,$billing_mode,$discount_type,$discount_value,$tax_percent,
      $subtotal,$total,$terms,$estimated,$weather,$deposit_type,
      $deposit_amount,$deposit_paid,$fulfillment_date,$scope,$memo,$customFieldsJson,
      $longTerm['start_date'],$longTerm['end_date'],$longTerm['billing_interval_count'],$longTerm['billing_interval_unit'],$longTerm['pricing_type'],
      $longTerm['price_per_invoice'],$longTerm['billing_start_mode'],$longTerm['invoice_count'],$longTerm['next_invoice_date'],$serviceLocationId,
      $id
    ]);
    if ($longTerm['pricing_type'] === 'per_invoice') {
      $baseServiceId = pa_recurring_service_ensure_base($pdo, $id);
      pa_recurring_service_sync_base($pdo, $id);
      pa_recurring_service_sync_contract_next_date($pdo, $id);
      $oldBilling = [
        'amount' => (float)($existingBaseService['amount'] ?? $existingContract['price_per_invoice'] ?? 0),
        'billing_interval_count' => (int)($existingBaseService['billing_interval_count'] ?? $existingContract['billing_interval_count'] ?? 1),
        'billing_interval_unit' => (string)($existingBaseService['billing_interval_unit'] ?? $existingContract['billing_interval_unit'] ?? 'month'),
        'effective_from' => $existingBaseService['effective_from'] ?? $existingContract['start_date'] ?? null,
        'effective_until' => $existingBaseService['effective_until'] ?? $existingContract['end_date'] ?? null,
        'next_invoice_date' => $existingBaseService['next_invoice_date'] ?? $existingContract['next_invoice_date'] ?? null,
      ];
      $newBilling = [
        'amount' => (float)$longTerm['price_per_invoice'],
        'billing_interval_count' => (int)$longTerm['billing_interval_count'],
        'billing_interval_unit' => (string)$longTerm['billing_interval_unit'],
        'effective_from' => $longTerm['start_date'],
        'effective_until' => $longTerm['end_date'],
        'next_invoice_date' => $longTerm['next_invoice_date'],
      ];
      if ($oldBilling !== $newBilling) {
        pa_recurring_service_record_amendment(
          $pdo, $id, $baseServiceId, 'service_updated', 'approved', date('Y-m-d'),
          'Base recurring service billing terms updated', $oldBilling, $newBilling, null,
          (int)($_SESSION['user']['id'] ?? 0) ?: null
        );
      }
    } else {
      $pdo->prepare('UPDATE contract_recurring_services SET status="ended",next_invoice_date=NULL WHERE contract_id=? AND is_base=1 AND status<>"ended"')->execute([$id]);
    }
  } else {
    $pdo->prepare('UPDATE contracts SET client_id=?, project_id=?, billing_mode=?, discount_type=?, discount_value=?, tax_percent=?, subtotal=?, total=?, terms=?, estimated_completion=?, weather_pending=?, deposit_type=?, deposit_amount=?, deposit_paid=?, fulfillment_date=?, scope=?, memo=?, custom_fields=?, service_location_id=? WHERE id=?')->execute([$client_id,$project_id,$billing_mode,$discount_type,$discount_value,$tax_percent,$subtotal,$total,$terms,$estimated,$weather,$deposit_type,$deposit_amount,$deposit_paid,$fulfillment_date,$scope,$memo,$customFieldsJson,$serviceLocationId,$id]);
  }
  
  // Sync changes to regular linked invoices. Long-term recurring invoices are historical billing records and must not be rewritten.
  if (!$isLongTermContract) {
    $invoiceDiscount=$discount_type==='percent'?max(0,min(100,$discount_value))*$invoiceSubtotal/100:($discount_type==='fixed'?min($invoiceSubtotal,max(0,$discount_value)):0);$invoiceTotal=max(0,$invoiceSubtotal-$invoiceDiscount+max(0,$tax_percent)*max(0,$invoiceSubtotal-$invoiceDiscount)/100);
    $pdo->prepare('UPDATE invoices SET client_id=?, project_id=?, billing_mode=?, discount_type=?, discount_value=?, tax_percent=?, subtotal=?, total=?, estimated_completion=?, fulfillment_date=?, weather_pending=?, scope=?, service_location_id=? WHERE contract_id=?')->execute([$client_id,$project_id,$billing_mode,$discount_type,$discount_value,$tax_percent,$invoiceSubtotal,$invoiceTotal,$estimated,$fulfillment_date,$weather,$scope,$serviceLocationId,$id]);
  }
  $pdo->prepare('DELETE FROM project_documents WHERE document_type="contract" AND document_id=?')->execute([$id]);
  if ($project_id) {
    $pdo->prepare('INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?, "contract", ?)')->execute([$project_id, $id]);
  }
  if (!$isLongTermContract) {
    $linkedInvoiceIds = $pdo->prepare('SELECT id FROM invoices WHERE contract_id=?');
    $linkedInvoiceIds->execute([$id]);
    foreach ($linkedInvoiceIds->fetchAll(PDO::FETCH_COLUMN) as $linkedInvoiceId) {
      $pdo->prepare('DELETE FROM project_documents WHERE document_type="invoice" AND document_id=?')->execute([(int)$linkedInvoiceId]);
      if ($project_id) {
        $pdo->prepare('INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?, "invoice", ?)')->execute([$project_id, (int)$linkedInvoiceId]);
      }
    }
  }
  
  // Sync items to regular linked invoices only.
  if (!$isLongTermContract) {
    $invoiceIds = $pdo->prepare('SELECT id FROM invoices WHERE contract_id=?');
    $invoiceIds->execute([$id]);
    foreach($invoiceIds->fetchAll(PDO::FETCH_COLUMN) as $invId) {
      $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id=?')->execute([$invId]);
      $insInv=$pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total, billing_unit) VALUES (?,?,?,?,?,?,?)');
      foreach($items as $it){ $insInv->execute([$invId,$it['i'],$it['d'],$it['q'],$it['p'],$it['t'],$it['u']]); }
      if($travelItem&&$travelItem['pricing_status']==='standard')$pdo->prepare('INSERT INTO invoice_items (invoice_id,item,description,quantity,unit_price,line_total,billing_unit,is_travel,pricing_status) VALUES (?,?,?,?,?,?,?,1,"standard")')->execute([$invId,$travelItem['item'],$travelItem['description'],$travelItem['quantity'],$travelItem['unit_price'],$travelItem['line_total'],$travelItem['billing_unit']]);
    }
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
  $ins=$pdo->prepare('INSERT INTO contract_items (contract_id, item, description, quantity, unit_price, line_total, billing_unit) VALUES (?,?,?,?,?,?,?)');
  foreach($items as $it){ $ins->execute([$id,$it['i'],$it['d'],$it['q'],$it['p'],$it['t'],$it['u']]); }
  if($travelItem)$pdo->prepare('INSERT INTO contract_items (contract_id,item,description,quantity,unit_price,line_total,billing_unit,is_travel,pricing_status) VALUES (?,?,?,?,?,?,?,1,?)')->execute([$id,$travelItem['item'],$travelItem['description'],$travelItem['quantity'],$travelItem['unit_price'],$travelItem['line_total'],$travelItem['billing_unit'],$travelItem['pricing_status']]);
  mileage_save_document_rule($pdo,'contract',$id,($existingContract['organization_id']??null),$client_id,(int)($_SESSION['user']['id']??0),$travelRule);
  
  // Save contract signatures (non-critical; failures must not roll back contract update)
  try {
      $pdo->prepare('DELETE FROM contract_signatures WHERE contract_id=?')->execute([$id]);
      $signatureTitles = $_POST['signature_titles'] ?? [];
      $signatureOrders = $_POST['signature_orders'] ?? [];
      $signatureRequired = $_POST['signature_required'] ?? [];
      if (!empty($signatureTitles)) {
          pa_save_contract_signatures($pdo, $id, $signatureTitles, $signatureOrders, $signatureRequired);
      }
  } catch (Throwable $sigErr) {
      @error_log('contracts_update signature insert failed: ' . $sigErr->getMessage());
  }
  if (!$isLongTermContract) {
    $linkedRevisions = $pdo->prepare('SELECT id,last_sent_revision FROM invoices WHERE contract_id=?');
    $linkedRevisions->execute([$id]);
    foreach ($linkedRevisions->fetchAll(PDO::FETCH_ASSOC) as $linkedInvoice) {
      DocumentRevisionService::snapshotAndSave($pdo,'invoice',(int)$linkedInvoice['id'],(int)($_SESSION['user']['id']??0));
      if (!empty($linkedInvoice['last_sent_revision'])) {
        $pdo->prepare('UPDATE public_links SET revoked=1 WHERE document_type="invoice" AND document_id=?')->execute([(int)$linkedInvoice['id']]);
      }
    }
  }
  DocumentRevisionService::snapshotAndSave($pdo,'contract',$id,(int)($_SESSION['user']['id']??0));
  if(!empty($existingContract['job_id']))ScheduleService::syncJob($pdo,(int)$existingContract['job_id'],(string)($appConfig['timezone']??'UTC'),(int)($_SESSION['user']['id']??0));
  
  $pdo->commit();
}catch(Throwable $e){ $pdo->rollBack(); header('Location: /?page=' . $detailPage . '&id=' . $id . '&error=Update%20failed'); exit; }
header('Location: /?page=' . $detailPage . '&id=' . $id . '&updated=1');
exit;
