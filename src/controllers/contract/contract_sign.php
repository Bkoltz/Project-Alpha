<?php
// src/controllers/contract_sign.php
// Handles uploading a signed PDF and activating the contract (no invoice creation here)
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';

$contract_id = (int)($_POST['id'] ?? 0);
if ($contract_id <= 0) { header('Location: /?page=contracts-list&error=Invalid%20contract'); exit; }

// Validate upload
if (empty($_FILES['signed_pdf']) || !is_uploaded_file($_FILES['signed_pdf']['tmp_name'])) {
  header('Location: /?page=contracts-list&error=' . urlencode('Please upload a signed PDF'));
  exit;
}
$f = $_FILES['signed_pdf'];
// Max 25 MB
if (!empty($f['size']) && $f['size'] > 25 * 1024 * 1024) {
  header('Location: /?page=contracts-list&error=' . urlencode('File too large (max 25 MB)'));
  exit;
}
$mime = @mime_content_type($f['tmp_name']);
$origName = (string)($f['name'] ?? '');
$extOk = preg_match('/\.pdf$/i', $origName) === 1;
if ($mime !== 'application/pdf' && !$extOk) {
  header('Location: /?page=contracts-list&error=' . urlencode('Only PDF files are accepted (must be .pdf)'));
  exit;
}

$pdo->beginTransaction();
try {
  $c = $pdo->prepare('SELECT * FROM contracts WHERE id=? FOR UPDATE');
  $c->execute([$contract_id]);
  $contract = $c->fetch(PDO::FETCH_ASSOC);
  if (!$contract) throw new Exception('Not found');

  // Store signed PDF in internal uploads and serve via controller
  $internal = __DIR__ . '/../uploads';
  if (!is_dir($internal)) { @mkdir($internal, 0775, true); }
  $name = 'contract_' . $contract_id . '_signed_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
  $internalDest = $internal . '/' . $name;
  if (@move_uploaded_file($f['tmp_name'], $internalDest) || @rename($f['tmp_name'], $internalDest) || @copy($f['tmp_name'], $internalDest)) {
    @unlink($f['tmp_name']);
  } else {
    throw new Exception('Failed to store uploaded file');
  }
  $publicUrl = '/?page=serve-upload&file=' . rawurlencode($name);

  // Save path and activate
  $pdo->prepare('UPDATE contracts SET signed_pdf_path=?, status=? WHERE id=?')->execute([$publicUrl, 'active', $contract_id]);

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Location: /?page=contracts-list&error=' . urlencode($e->getMessage()));
  exit;
}

header('Location: /?page=contracts-list&signed=1');
exit;
