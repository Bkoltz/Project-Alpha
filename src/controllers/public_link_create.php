<?php
// src/controllers/public_link_create.php
// Generates a public shareable link for quotes, contracts, or invoices

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json');

$type = $_POST['type'] ?? '';
$id = (int)($_POST['id'] ?? 0);
$days = (int)($_POST['days'] ?? 0);
$expireWhenPaid = isset($_POST['expire_when_paid']) && $_POST['expire_when_paid'] === '1';
$forceNew = isset($_POST['force_new']) && $_POST['force_new'] === '1';

if (!in_array($type, ['quote', 'contract', 'invoice'], true) || $id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

// "Expire when paid" only applies to invoices
if ($expireWhenPaid && $type !== 'invoice') {
    $expireWhenPaid = false;
}

// Use default days if not specified (unless expire_when_paid is set)
if (!$expireWhenPaid && $days <= 0) {
    $days = isset($appConfig['documents_valid_days']) ? (int)$appConfig['documents_valid_days'] : 14;
}
if (!$expireWhenPaid && $days <= 0) {
    $days = 14;
}

try {
    // Verify the document exists
    $validTables = ['quote' => 'quotes', 'contract' => 'contracts', 'invoice' => 'invoices'];
    if (!isset($validTables[$type])) {
        echo json_encode(['success' => false, 'error' => 'Invalid document type']);
        exit;
    }
    $table = $validTables[$type];
    $stmt = $pdo->prepare("SELECT id, status FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doc) {
        echo json_encode(['success' => false, 'error' => ucfirst($type) . ' not found']);
        exit;
    }
    
    // Check if document is in a shareable state
    $status = strtolower($doc['status'] ?? '');
    $blocked = false;
    if ($type === 'quote' && $status === 'rejected') {
        $blocked = true;
    } elseif ($type === 'contract' && in_array($status, ['denied', 'cancelled', 'void'], true)) {
        $blocked = true;
    } elseif ($type === 'invoice' && $status === 'void') {
        $blocked = true;
    }
    
    if ($blocked) {
        echo json_encode(['success' => false, 'error' => 'Cannot create link for a ' . $status . ' ' . $type]);
        exit;
    }
    
    // Ensure public_links table exists with proper columns
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS public_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(64) NOT NULL UNIQUE,
            document_type VARCHAR(50) NOT NULL,
            document_id INT NOT NULL,
            expires_at DATETIME NULL,
            expire_when_paid TINYINT(1) NOT NULL DEFAULT 0,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            redirect VARCHAR(500) NULL,
            access_count INT NOT NULL DEFAULT 0,
            last_accessed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_public_token (token),
            INDEX idx_public_type_doc (document_type, document_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Throwable $e) { /* table exists */ }
    
    // If force_new is set, revoke all existing links for this document
    if ($forceNew) {
        $revokeSt = $pdo->prepare('UPDATE public_links SET revoked = 1 WHERE document_type = ? AND document_id = ? AND revoked = 0');
        $revokeSt->execute([$type, $id]);
    }
    
    // Check if a valid link already exists for this document (only if not forcing new)
    $existing = null;
    if (!$forceNew) {
        $existingStmt = $pdo->prepare('
            SELECT token, expires_at, expire_when_paid 
            FROM public_links 
            WHERE document_type = ? AND document_id = ? AND revoked = 0 
            AND (expire_when_paid = 1 OR expires_at > NOW())
            ORDER BY created_at DESC 
            LIMIT 1
        ');
        $existingStmt->execute([$type, $id]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($existing) {
        // Return existing valid link
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '' && !empty($appConfig['app_host'])) {
            $host = (string)$appConfig['app_host'];
        }
        if ($host === '') {
            $host = 'localhost';
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $publicUrl = $scheme . '://' . $host . '/?page=public-doc&token=' . rawurlencode($existing['token']);
        
        $expiresAt = $existing['expire_when_paid'] ? 'When invoice is paid' : $existing['expires_at'];
        $expiresInDays = null;
        if (!$existing['expire_when_paid'] && $existing['expires_at']) {
            $expiresInDays = max(0, (int)ceil((strtotime($existing['expires_at']) - time()) / 86400));
        }
        
        echo json_encode([
            'success' => true,
            'url' => $publicUrl,
            'token' => $existing['token'],
            'expires_at' => $expiresAt,
            'expires_in_days' => $expiresInDays,
            'expire_when_paid' => (bool)$existing['expire_when_paid'],
            'existing' => true
        ]);
        exit;
    }
    
    // Generate new token
    $token = bin2hex(random_bytes(16));
    
    // Calculate expiration
    $exp = null;
    if (!$expireWhenPaid) {
        $exp = date('Y-m-d H:i:s', time() + ($days * 24 * 60 * 60));
    }
    
    // Insert new link
    $ins = $pdo->prepare('INSERT INTO public_links (document_type, document_id, token, expires_at, expire_when_paid) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([$type, $id, $token, $exp, $expireWhenPaid ? 1 : 0]);
    
    // Build absolute URL
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '' && !empty($appConfig['app_host'])) {
        $host = (string)$appConfig['app_host'];
    }
    if ($host === '') {
        $host = 'localhost';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $publicUrl = $scheme . '://' . $host . '/?page=public-doc&token=' . rawurlencode($token);
    
    echo json_encode([
        'success' => true,
        'url' => $publicUrl,
        'token' => $token,
        'expires_at' => $expireWhenPaid ? 'When invoice is paid' : $exp,
        'expires_in_days' => $expireWhenPaid ? null : $days,
        'expire_when_paid' => $expireWhenPaid,
        'existing' => false
    ]);
    
} catch (Throwable $e) {
    @error_log('[PublicLinkCreate] Error: ' . $e->getMessage());
    @error_log('[PublicLinkCreate] Stack trace: ' . $e->getTraceAsString());
    echo json_encode(['success' => false, 'error' => 'Failed to create link: ' . $e->getMessage()]);
}
