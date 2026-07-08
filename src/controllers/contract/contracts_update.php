<?php
// src/controllers/contracts_update.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/document_fields.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/acl_middleware.php';
require_once __DIR__ . '/../../utils/project_selection.php';
require_once __DIR__ . '/../../utils/contract_signatures.php';
$id = (int)($_POST['id'] ?? 0);
require_record_ownership($pdo, 'contracts', $id);
$client_id = (int)($_POST['client_id'] ?? 0);
$project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
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
if ($id<=0 || $client_id<=0) { header('Location: /?page=contract/contracts-list&error=Invalid'); exit; }
$contractTypeStmt = $pdo->prepare('SELECT * FROM contracts WHERE id=? LIMIT 1');
$contractTypeStmt->execute([$id]);
$existingContract = $contractTypeStmt->fetch(PDO::FETCH_ASSOC);
$contractType = (string)($existingContract['contract_type'] ?? '');
$isLongTermContract = $contractType === 'long_term';
$detailPage = $contractType === 'long_term' ? 'contract/long-term-contract-details' : 'contract/contract-details';
if ($project_id && !pa_project_is_active_for_client($pdo, $project_id, $client_id, (int)($_SESSION['user']['id'] ?? 0))) {
  header('Location: /?page=contract/contracts-edit&id=' . $id . '&error=' . urlencode('Select an active or not-started project for this client or organization.'));
  exit;
}
$items=[];$subtotal=0.0;
for($i=0;$i<count($item);$i++){
  $itm=trim((string)($item[$i]??'')); $d=trim((string)($desc[$i]??'')); $q=(float)($qty[$i]??0); $p=(float)($price[$i]??0);
  if($itm===''||$q<=0||$p<0) continue; $line=$q*$p; $subtotal+=$line; $unit=(($billingUnits[$i]??'each')==='hour'||$billing_mode==='hourly')?'hour':'each'; $items[]=['i'=>$itm,'d'=>$d,'q'=>$q,'p'=>$p,'t'=>$line,'u'=>$unit];
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
  if ($isLongTermContract && $longTerm) {
    $pdo->prepare('
      UPDATE contracts
      SET client_id=?, project_id=?, billing_mode=?, discount_type=?, discount_value=?, tax_percent=?,
          subtotal=?, total=?, terms=?, estimated_completion=?, weather_pending=?, deposit_type=?,
          deposit_amount=?, deposit_paid=?, fulfillment_date=?, scope=?, memo=?, custom_fields=?,
          start_date=?, end_date=?, billing_interval_count=?, billing_interval_unit=?, pricing_type=?,
          price_per_invoice=?, billing_start_mode=?, invoice_count=?, next_invoice_date=?
      WHERE id=?
    ')->execute([
      $client_id,$project_id,$billing_mode,$discount_type,$discount_value,$tax_percent,
      $subtotal,$total,$terms,$estimated,$weather,$deposit_type,
      $deposit_amount,$deposit_paid,$fulfillment_date,$scope,$memo,$customFieldsJson,
      $longTerm['start_date'],$longTerm['end_date'],$longTerm['billing_interval_count'],$longTerm['billing_interval_unit'],$longTerm['pricing_type'],
      $longTerm['price_per_invoice'],$longTerm['billing_start_mode'],$longTerm['invoice_count'],$longTerm['next_invoice_date'],
      $id
    ]);
  } else {
    $pdo->prepare('UPDATE contracts SET client_id=?, project_id=?, billing_mode=?, discount_type=?, discount_value=?, tax_percent=?, subtotal=?, total=?, terms=?, estimated_completion=?, weather_pending=?, deposit_type=?, deposit_amount=?, deposit_paid=?, fulfillment_date=?, scope=?, memo=?, custom_fields=? WHERE id=?')->execute([$client_id,$project_id,$billing_mode,$discount_type,$discount_value,$tax_percent,$subtotal,$total,$terms,$estimated,$weather,$deposit_type,$deposit_amount,$deposit_paid,$fulfillment_date,$scope,$memo,$customFieldsJson,$id]);
  }
  
  // Sync changes to regular linked invoices. Long-term recurring invoices are historical billing records and must not be rewritten.
  if (!$isLongTermContract) {
    $pdo->prepare('UPDATE invoices SET client_id=?, project_id=?, billing_mode=?, discount_type=?, discount_value=?, tax_percent=?, subtotal=?, total=?, estimated_completion=?, fulfillment_date=?, weather_pending=?, scope=? WHERE contract_id=?')->execute([$client_id,$project_id,$billing_mode,$discount_type,$discount_value,$tax_percent,$subtotal,$total,$estimated,$fulfillment_date,$weather,$scope,$id]);
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
  
  $pdo->commit();
}catch(Throwable $e){ $pdo->rollBack(); header('Location: /?page=' . $detailPage . '&id=' . $id . '&error=Update%20failed'); exit; }
header('Location: /?page=' . $detailPage . '&id=' . $id . '&updated=1');
exit;
