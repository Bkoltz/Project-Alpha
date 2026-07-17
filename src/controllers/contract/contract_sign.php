<?php
// src/controllers/contract_sign.php
// Handles uploading a signed PDF and activating the contract (no invoice creation here)
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/contract_billing_start.php';
require_once __DIR__ . '/../../utils/public_links.php';
require_once __DIR__ . '/../../utils/job_work_materialization.php';
require_once __DIR__ . '/../../utils/upload_validator.php';

$contract_id = (int)($_POST['id'] ?? 0);
if ($contract_id <= 0) { header('Location: /?page=contract/contracts-list&error=Invalid%20contract'); exit; }
require_record_ownership($pdo, 'contracts', $contract_id);

$typeStmt = $pdo->prepare('SELECT contract_type, status, signed_at, signed_pdf_path FROM contracts WHERE id=?');
$typeStmt->execute([$contract_id]);
$contractMeta = $typeStmt->fetch(PDO::FETCH_ASSOC);
if (!$contractMeta) {
  header('Location: /?page=contract/contracts-list&error=Contract%20not%20found');
  exit;
}
$contractType = (string)($contractMeta['contract_type'] ?: 'regular');
$listPage = match ($contractType) {
  'long_term' => 'contract/long-term-contracts-list',
  'on_demand' => 'contract/on-demand-contracts-list',
  default => 'contract/contracts-list',
};
$redirectError = static function (string $message) use ($listPage): never {
  header('Location: /?page=' . $listPage . '&error=' . urlencode($message));
  exit;
};

if (!in_array((string)$contractMeta['status'], ['draft', 'pending'], true)) {
  $redirectError('Only draft or pending contracts can receive a signed PDF');
}
if (!empty($contractMeta['signed_at']) || trim((string)($contractMeta['signed_pdf_path'] ?? '')) !== '') {
  $redirectError('This contract is already signed. Use an amendment or void and reissue it.');
}

if (empty($_FILES['signed_pdf']) || !is_array($_FILES['signed_pdf'])) {
  $redirectError('Please upload a signed PDF');
}
$f = $_FILES['signed_pdf'];
$internal = __DIR__ . '/../../uploads/signed_contracts';
$uploadError = null;
$name = validate_and_store_upload(
  $f,
  ['application/pdf' => 'pdf'],
  25 * 1024 * 1024,
  $internal,
  $uploadError,
  [
    'reject_archives' => true,
    'require_pdf_header' => true,
    'reject_pdf_active_content' => true,
  ]
);
if ($name === null) {
  $redirectError($uploadError ?: 'Failed to store uploaded PDF');
}
$internalDest = $internal . DIRECTORY_SEPARATOR . $name;
$storedFile = $internalDest;

$pdo->beginTransaction();
try {
  $c = $pdo->prepare('SELECT * FROM contracts WHERE id=? FOR UPDATE');
  $c->execute([$contract_id]);
  $contract = $c->fetch(PDO::FETCH_ASSOC);
  if (!$contract) throw new Exception('Not found');
  if (!in_array((string)$contract['status'], ['draft', 'pending'], true)) throw new Exception('Only draft or pending contracts can receive a signed PDF');
  if (!empty($contract['signed_at']) || trim((string)($contract['signed_pdf_path']??''))!=='') throw new Exception('This contract is already signed. Use an amendment or void and reissue it.');
  
  $publicUrl = '/?page=serve-upload&file=' . rawurlencode('signed_contracts/' . $name);

  // Save the signed PDF. For REGULAR contracts, auto-activate on upload (existing behavior).
  // For LONG-TERM and ON-DEMAND, keep the contract pending until the user explicitly activates it.
  // This preserves explicit user intent and prevents accidental activation.
  $ctType = $contract['contract_type'] ?? 'regular';
  $signedHash = hash_file('sha256',$internalDest);
  $signedRevision = max(1,(int)($contract['revision_number']??1));
  if ($ctType === 'regular') {
    $pdo->prepare('UPDATE contracts SET signed_pdf_path=?,status=?,signed_at=NOW(),signed_revision_number=?,signed_pdf_sha256=? WHERE id=? AND signed_at IS NULL AND (signed_pdf_path IS NULL OR signed_pdf_path="")')
        ->execute([$publicUrl,'active',$signedRevision,$signedHash,$contract_id]);
  } elseif (pa_long_term_starts_billing_on_upload($contract)) {
    $pdo->prepare('UPDATE contracts SET signed_pdf_path=?,next_invoice_date=?,signed_at=NOW(),signed_revision_number=?,signed_pdf_sha256=? WHERE id=? AND signed_at IS NULL AND (signed_pdf_path IS NULL OR signed_pdf_path="")')
        ->execute([$publicUrl,date('Y-m-d'),$signedRevision,$signedHash,$contract_id]);
  } else {
    // LT/OD: just save the path and leave status unchanged; the user clicks Activate separately.
    $pdo->prepare('UPDATE contracts SET signed_pdf_path=?,signed_at=NOW(),signed_revision_number=?,signed_pdf_sha256=? WHERE id=? AND signed_at IS NULL AND (signed_pdf_path IS NULL OR signed_pdf_path="")')
        ->execute([$publicUrl,$signedRevision,$signedHash,$contract_id]);
  }
  if($pdo->query('SELECT ROW_COUNT()')->fetchColumn()!=1)throw new Exception('This contract was signed by another request. The existing signed file was not replaced.');

  pa_public_link_terminalize($pdo, 'contract', $contract_id, 'signed');
  catalog_plan_direct_contract($pdo, $contract_id, (int)($_SESSION['user']['id'] ?? 0));

  $pdo->commit();
  $storedFile = null;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  if (is_string($storedFile) && is_file($storedFile)) {
    @unlink($storedFile);
  }
  $redirectError($e->getMessage());
}

// For long-term contracts that are already active, re-uploading a signed PDF can trigger
// billing if the next invoice date is due. This does not run on first upload because
// long-term contracts are not auto-activated.
if ($contractType === 'long_term') {
  try {
    require_once __DIR__ . '/../../utils/recurring_billing.php';
    $cStmt = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="long_term"');
    $cStmt->execute([$contract_id]);
    $ltContract = $cStmt->fetch(PDO::FETCH_ASSOC);
    if ($ltContract && $ltContract['status'] === 'active' && !empty($ltContract['next_invoice_date']) && $ltContract['next_invoice_date'] <= date('Y-m-d')) {
      generate_recurring_invoice($pdo, $ltContract, $appConfig);
    }
  } catch (Throwable $e) {
    @error_log('[contract_sign] LT first-invoice generation failed: ' . $e->getMessage());
  }
}

header('Location: /?page=' . $listPage . '&signed=1');
exit;
