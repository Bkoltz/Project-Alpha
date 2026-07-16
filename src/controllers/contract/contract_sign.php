<?php
// src/controllers/contract_sign.php
// Handles uploading a signed PDF and activating the contract (no invoice creation here)
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/contract_billing_start.php';
require_once __DIR__ . '/../../utils/public_links.php';
require_once __DIR__ . '/../../utils/job_work_materialization.php';

$contract_id = (int)($_POST['id'] ?? 0);
if ($contract_id <= 0) { header('Location: /?page=contract/contracts-list&error=Invalid%20contract'); exit; }
require_record_ownership($pdo, 'contracts', $contract_id);

// Validate upload
if (empty($_FILES['signed_pdf']) || !is_uploaded_file($_FILES['signed_pdf']['tmp_name'])) {
  header('Location: /?page=contract/contracts-list&error=' . urlencode('Please upload a signed PDF'));
  exit;
}
$f = $_FILES['signed_pdf'];
// Max 25 MB
if (!empty($f['size']) && $f['size'] > 25 * 1024 * 1024) {
  header('Location: /?page=contract/contracts-list&error=' . urlencode('File too large (max 25 MB)'));
  exit;
}
$mime = @mime_content_type($f['tmp_name']);
$origName = (string)($f['name'] ?? '');
$extOk = preg_match('/\.pdf$/i', $origName) === 1;
if ($mime !== 'application/pdf' && !$extOk) {
  header('Location: /?page=contract/contracts-list&error=' . urlencode('Only PDF files are accepted (must be .pdf)'));
  exit;
}

// Diagnostic logging to help debug upload/save failures
$pdo->beginTransaction();
error_log('UPLOAD: contract_sign start; contract_id=' . $contract_id . ' POST_keys=' . json_encode(array_keys($_POST)) . ' FILE_keys=' . json_encode(array_keys($_FILES)));
try {
  $c = $pdo->prepare('SELECT * FROM contracts WHERE id=? FOR UPDATE');
  $c->execute([$contract_id]);
  $contract = $c->fetch(PDO::FETCH_ASSOC);
  if (!$contract) throw new Exception('Not found');
  if (!empty($contract['signed_at']) || trim((string)($contract['signed_pdf_path']??''))!=='') throw new Exception('This contract is already signed. Use an amendment or void and reissue it.');
  
  // Note: Deposit and signed contract can be received in any order
  // We no longer require deposit to be received before signing

  // Store signed PDF in src/uploads/signed_contracts for organization and separation
  $internal = __DIR__ . '/../../uploads/signed_contracts';
  if (!is_dir($internal)) { @mkdir($internal, 0775, true); }
  // Log file upload metadata
  error_log('UPLOAD: tmp_name=' . ($f['tmp_name'] ?? '') . ' size=' . ($f['size'] ?? 0) . ' error=' . ($f['error'] ?? ''));
  error_log('UPLOAD: using src/uploads/signed_contracts dir ' . $internal);
  $name = 'contract_' . $contract_id . '_signed_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
  $internalDest = $internal . '/' . $name;
  $moved = false;
  // Try move_uploaded_file first (preferred)
  if (!empty($f['tmp_name']) && is_uploaded_file($f['tmp_name'])) {
    $moved = @move_uploaded_file($f['tmp_name'], $internalDest);
    error_log('UPLOAD: move_uploaded_file result=' . ($moved ? '1' : '0') . ' dest=' . $internalDest);
  }
  // Fall back to rename/copy if needed
  if (!$moved && !empty($f['tmp_name'])) {
    $moved = @rename($f['tmp_name'], $internalDest);
    error_log('UPLOAD: rename result=' . ($moved ? '1' : '0'));
  }
  if (!$moved && !empty($f['tmp_name'])) {
    $moved = @copy($f['tmp_name'], $internalDest);
    error_log('UPLOAD: copy result=' . ($moved ? '1' : '0'));
  }
  if ($moved) {
    @unlink($f['tmp_name']);
    error_log('UPLOAD: saved to ' . $internalDest);
  } else {
    // Log diagnostics about permissions and paths
    error_log('UPLOAD: FAILED to store uploaded file. tmp_exists=' . (is_file($f['tmp_name']) ? '1' : '0') . ' internal_exists=' . (is_dir($internal)?'1':'0') . ' internal_writable=' . (is_writable($internal)?'1':'0') . ' cwd=' . getcwd());
    throw new Exception('Failed to store uploaded file');
  }
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
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Location: /?page=contract/contracts-list&error=' . urlencode($e->getMessage()));
  exit;
}

// Determine the contract type for a correct redirect target.
$contractType = 'regular';
try {
  $tStmt = $pdo->prepare('SELECT contract_type FROM contracts WHERE id=?');
  $tStmt->execute([$contract_id]);
  $contractType = (string)($tStmt->fetchColumn() ?: 'regular');
} catch (Throwable $e) { /* default regular */ }

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

// Type-aware redirect so the user lands back on the correct list.
$listPage = 'contract/contracts-list';
if ($contractType === 'long_term') {
  $listPage = 'contract/long-term-contracts-list';
} elseif ($contractType === 'on_demand') {
  $listPage = 'contract/on-demand-contracts-list';
}
header('Location: /?page=' . $listPage . '&signed=1');
exit;
