<?php
// src/controllers/public_view/public_contract_sign.php
// Handles signed contract upload from public link
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
if (!rate_limit_check($pdo, 'public_contract_sign', 30, 60)) {
  http_response_code(429);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!DOCTYPE html><html><head><title>Rate limited</title></head><body><h1>Rate limited</h1></body></html>';
  exit;
}
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/notifications.php';
require_once __DIR__ . '/../../utils/upload_validator.php';
require_once __DIR__ . '/../../utils/contract_billing_start.php';

// Verify CSRF
$submitted = (string)($_POST['_token'] ?? ($_POST['csrf'] ?? ''));
if (!csrf_sf_is_valid('public_contract_sign', $submitted)) {
    header('Location: /?page=public-doc&error=' . urlencode('Invalid request'));
    exit;
}

try {
    $token = isset($_POST['token']) ? (string)$_POST['token'] : '';
    
    if (empty($token)) {
        throw new Exception('Missing token');
    }
    
    // Validate file upload
    if (!isset($_FILES['signed_pdf']) || $_FILES['signed_pdf']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Please select a file to upload');
    }
    
    $file = $_FILES['signed_pdf'];
    $maxSize = 10 * 1024 * 1024; // 10MB
    
    if ($file['size'] > $maxSize) {
        throw new Exception('File too large (max 10MB)');
    }
    
    // Check file type (PDF or images)
    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes, true)) {
        throw new Exception('Invalid file type. Please upload a PDF or image file.');
    }
    
    // Load and validate public link
    $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked FROM public_links WHERE token=? LIMIT 1');
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        throw new Exception('Link not found');
    }
    
    if ((int)($row['revoked'] ?? 0) === 1) {
        throw new Exception('Link has expired');
    }
    
    if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) {
        throw new Exception('Link has expired');
    }
    
    if ($row['document_type'] !== 'contract') {
        throw new Exception('Invalid link type');
    }
    
    $contractId = (int)$row['document_id'];
    
    // Get contract and client info
    $contractStmt = $pdo->prepare('SELECT co.*, c.name as client_name, c.id as client_id FROM contracts co JOIN clients c ON c.id = co.client_id WHERE co.id = ?');
    $contractStmt->execute([$contractId]);
    $contract = $contractStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contract) {
        throw new Exception('Contract not found');
    }
    
    // Check contract status. Public signing is intentionally one-time:
    // a contract must still be pending and unsigned at the moment the upload
    // is accepted.
    $status = strtolower($contract['status'] ?? '');
    if ($status !== 'pending' || !empty($contract['signed_pdf_path'])) {
        throw new Exception('This contract has already been signed or is no longer pending');
    }
    
    // Build allowed MIME → extension map for signed contracts.
    $allowedMap = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
                   'image/gif' => 'gif', 'image/webp' => 'webp'];

    // Centralized validation, malware scan, and secure storage.
    $uploadDir = __DIR__ . '/../../uploads/signed_contracts';
    $uploadError = null;
    $filename = validate_and_store_upload(
        $file,
        $allowedMap,
        10 * 1024 * 1024,
        $uploadDir,
        $uploadError
    );
    if ($filename === null) {
        throw new Exception($uploadError ?: 'Failed to store uploaded file');
    }

    // Build the file URL - serve from signed_contracts subdirectory
    $fileUrl = '/?page=serve-upload&file=' . rawurlencode('signed_contracts/' . $filename);
    
    $pdo->beginTransaction();
    
    // Update contract with signed file path and set to active. Keep the
    // pending/unsigned predicate inside the write so concurrent submissions
    // cannot race and overwrite the first signature.
    $billingStartSql = '';
    $updateParams = [$fileUrl, 'active'];
    if (pa_long_term_starts_billing_on_upload($contract)) {
        $billingStartSql = ', next_invoice_date = ?';
        $updateParams[] = date('Y-m-d');
    }
    $updateParams[] = $contractId;

    $update = $pdo->prepare("
        UPDATE contracts
        SET signed_pdf_path = ?, status = ?{$billingStartSql}
        WHERE id = ?
          AND status = 'pending'
          AND (signed_pdf_path IS NULL OR signed_pdf_path = '')
    ");
    $update->execute($updateParams);
    if ($update->rowCount() !== 1) {
        $storedFile = $uploadDir . DIRECTORY_SEPARATOR . $filename;
        if (is_file($storedFile)) {
            @unlink($storedFile);
        }
        throw new Exception('This contract has already been signed or is no longer pending');
    }

    $revoke = $pdo->prepare('UPDATE public_links SET revoked = 1 WHERE token = ? AND document_type = ? AND document_id = ? AND revoked = 0');
    $revoke->execute([$token, 'contract', $contractId]);
    
    $pdo->commit();
    
    // Notify admin
    try {
        notify_admin_contract_signed($pdo, $appConfig, $contract, $contract['client_name'] ?? 'Client');
    } catch (Throwable $e) {
        @error_log('[public_contract_sign] Notification failed: ' . $e->getMessage());
    }
    
    // Redirect back with success
    header('Location: /?page=public-doc&token=' . rawurlencode($token) . '&signed=1');
    exit;
    
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    @error_log('[public_contract_sign] Error: ' . $e->getMessage());
    
    $t = isset($_POST['token']) ? (string)$_POST['token'] : '';
    header('Location: /?page=public-doc&token=' . rawurlencode($t) . '&error=' . urlencode($e->getMessage()));
    exit;
}
