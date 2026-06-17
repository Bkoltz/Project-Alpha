<?php
// src/controllers/public_view/public_contract_action.php
// Accept a signed contract PDF via a public tokenized link and save it to uploads.
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
if (!rate_limit_check($pdo, 'public_contract_action', 30, 60)) {
  http_response_code(429);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!DOCTYPE html><html><head><title>Rate limited</title></head><body><h1>Rate limited</h1></body></html>';
  exit;
}
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';

$submitted = (string)($_POST['_token'] ?? ($_POST['csrf'] ?? ''));
if (!csrf_sf_is_valid('public_contract_action', $submitted)) {
  header('Location: /?page=public-doc&error=' . urlencode('Invalid request'));
  exit;
}

try {
  $token = isset($_POST['token']) ? (string)$_POST['token'] : '';
  if ($token === '') { throw new Exception('notoken'); }

  // Validate public link
  $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked FROM public_links WHERE token=? LIMIT 1');
  $st->execute([$token]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { throw new Exception('notfound'); }
  if ((int)$row['revoked'] === 1 || strtotime((string)$row['expires_at']) < time()) { throw new Exception('expired'); }
  if ($row['document_type'] !== 'contract') { throw new Exception('badtype'); }
  $cid = (int)$row['document_id'];

  // Validate file
  if (empty($_FILES['signed_pdf']) || !is_uploaded_file($_FILES['signed_pdf']['tmp_name'])) {
    header('Location: /?page=public-doc&token=' . rawurlencode($token) . '&error=' . urlencode('Please upload a signed PDF'));
    exit;
  }
  $f = $_FILES['signed_pdf'];
  if (!empty($f['size']) && $f['size'] > 25 * 1024 * 1024) {
    header('Location: /?page=public-doc&token=' . rawurlencode($token) . '&error=' . urlencode('File too large (max 25 MB)'));
    exit;
  }
  $mime = @mime_content_type($f['tmp_name']);
  $origName = (string)($f['name'] ?? '');
  $extOk = preg_match('/\.pdf$/i', $origName) === 1;
  if ($mime !== 'application/pdf' && !$extOk) {
    header('Location: /?page=public-doc&token=' . rawurlencode($token) . '&error=' . urlencode('Only PDF files are accepted (must be .pdf)'));
    exit;
  }

  // Load contract and check allowed status
  $c = $pdo->prepare('SELECT * FROM contracts WHERE id=? FOR UPDATE');
  $c->execute([$cid]);
  $contract = $c->fetch(PDO::FETCH_ASSOC);
  if (!$contract) { throw new Exception('Not found'); }
  $status = (string)($contract['status'] ?? '');
  if ($status !== 'pending') {
    // if already active or cancelled, do not accept upload
    header('Location: /?page=public-doc&token=' . rawurlencode($token) . '&error=' . urlencode('Contract cannot be uploaded for current status'));
    exit;
  }

  // Store signed PDF in src/uploads directory (same logic as internal contract_sign)
  $internal = __DIR__ . '/../../uploads';
  if (!is_dir($internal)) { @mkdir($internal, 0775, true); }
  $name = 'contract_' . $cid . '_signed_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
  $internalDest = $internal . '/' . $name;
  $moved = false;
  if (!empty($f['tmp_name']) && is_uploaded_file($f['tmp_name'])) {
    $moved = @move_uploaded_file($f['tmp_name'], $internalDest);
  }
  if (!$moved && !empty($f['tmp_name'])) {
    $moved = @rename($f['tmp_name'], $internalDest);
  }
  if (!$moved && !empty($f['tmp_name'])) {
    $moved = @copy($f['tmp_name'], $internalDest);
  }
  if ($moved) { @unlink($f['tmp_name']); }
  else { throw new Exception('Failed to store uploaded file'); }

  $publicUrl = '/?page=serve-upload&file=' . rawurlencode($name);

  // Save path and activate contract
  $pdo->beginTransaction();
  try {
    $pdo->prepare('UPDATE contracts SET signed_pdf_path=?, status=?, signed_at=NOW() WHERE id=?')->execute([$publicUrl, 'active', $cid]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }

  // Redirect back to public view with success
  header('Location: /?page=public-doc&token=' . rawurlencode($token) . '&ok=1');
  exit;
} catch (Throwable $e) {
  $t = isset($token) ? (string)$token : '';
  header('Location: /?page=public-doc&token=' . rawurlencode($t) . '&error=' . urlencode('Unable to upload signed contract'));
  exit;
}
