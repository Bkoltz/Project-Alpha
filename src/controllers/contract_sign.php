<?php
// src/controllers/contract_sign.php
// Handles uploading a signed PDF and activating the contract (no invoice creation here)
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/recurring_billing.php';

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

  // Save path and activate
  $pdo->prepare('UPDATE contracts SET signed_pdf_path=?, status=? WHERE id=?')->execute([$publicUrl, 'active', $contract_id]);

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Location: /?page=contract/contracts-list&error=' . urlencode($e->getMessage()));
  exit;
}

// Type-aware redirect target.
$redirectMap = [
  'long_term' => 'contract/long-term-contracts-list',
  'on_demand' => 'contract/on-demand-contracts-list',
  'regular' => 'contract/contracts-list',
];
$contractType = (string)($contract['contract_type'] ?? 'regular');
$redirectPage = $redirectMap[$contractType] ?? $redirectMap['regular'];

// For long-term contracts, generate the first invoice immediately when due.
if ($contractType === 'long_term' && $contract && $contract['status'] === 'active') {
  // Ensure next_invoice_date is set for newly signed contracts.
  if (empty($contract['next_invoice_date'])) {
    try {
      $stmt = $pdo->prepare('UPDATE contracts SET next_invoice_date = ? WHERE id = ? AND contract_type = "long_term"');
      $stmt->execute([$contract['start_date'], $contract_id]);
      $contract['next_invoice_date'] = $contract['start_date'];
    } catch (Throwable $e) {
      @error_log('[contract_sign] Failed to set next_invoice_date for contract ' . $contract_id . ': ' . $e->getMessage());
    }
  }

  if (!empty($contract['next_invoice_date']) && $contract['next_invoice_date'] <= date('Y-m-d')) {
    try {
      generate_recurring_invoice($pdo, $contract, $appConfig);
    } catch (Throwable $e) {
      @error_log('[contract_sign] First invoice generation failed for contract ' . $contract_id . ': ' . $e->getMessage());
    }
  }
}

header('Location: /?page=' . $redirectPage . '&signed=1');
exit;
