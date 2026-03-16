<?php
// src/controllers/public_view/public_contract_sign.php
// Handles signed contract upload from public link
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/notifications.php';

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
    $st = $pdo->prepare('SELECT type, record_id, expires_at, revoked FROM public_links WHERE token=? LIMIT 1');
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
    
    if ($row['type'] !== 'contract') {
        throw new Exception('Invalid link type');
    }
    
    $contractId = (int)$row['record_id'];
    
    // Get contract and client info
    $contractStmt = $pdo->prepare('SELECT co.*, c.name as client_name, c.id as client_id FROM contracts co JOIN clients c ON c.id = co.client_id WHERE co.id = ?');
    $contractStmt->execute([$contractId]);
    $contract = $contractStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contract) {
        throw new Exception('Contract not found');
    }
    
    // Check contract status
    $status = strtolower($contract['status'] ?? '');
    if (in_array($status, ['cancelled', 'void', 'denied'], true)) {
        throw new Exception('This contract is no longer active');
    }
    
    // Generate unique filename
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $ext = $mimeType === 'application/pdf' ? 'pdf' : 'jpg';
    }
    $filename = 'signed_contract_' . $contractId . '_' . time() . '.' . $ext;
    
    // Determine upload directory - use src/uploads/signed_contracts
    $baseUploads = realpath(__DIR__ . '/../../uploads');
    if (!$baseUploads) {
        $baseUploads = __DIR__ . '/../../uploads';
    }
    $uploadDir = $baseUploads . DIRECTORY_SEPARATOR . 'signed_contracts';
    
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    
    $destPath = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new Exception('Failed to save file');
    }
    
    // Build the file URL - serve from signed_contracts subdirectory
    $fileUrl = '/?page=serve-upload&file=' . rawurlencode('signed_contracts/' . $filename);
    
    $pdo->beginTransaction();
    
    // Update contract with signed file path and set to active
    $pdo->prepare('UPDATE contracts SET signed_pdf_path = ?, status = ? WHERE id = ?')
        ->execute([$fileUrl, 'active', $contractId]);
    
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
