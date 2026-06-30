<?php
// src/controllers/account/data_export.php
// Personal data export for the authenticated PA app user.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';

if (empty($_SESSION['user']['id'])) {
    header('Location: /?page=login');
    exit;
}

$userId = (int)$_SESSION['user']['id'];

if (!csrf_validate()) {
    header('Location: /?page=account/data-export&error=' . rawurlencode('Invalid request (CSRF)'));
    exit;
}

$export = [
    'exported_at' => date('c'),
    'schema_note' => 'GDPR/CCPA Right to Access export. Contains personal account data associated with the authenticated PA user. Customer/client records are business data and are not included in this personal export.',
    'user' => null,
    'audit_trail' => [],
];

try {
    $stmt = $pdo->prepare('SELECT id, email, username, role, is_disabled, force_password_reset, created_at, tos_accepted_at, document_sender_enabled, document_sender_name, document_sender_company, document_sender_email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $export['user'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $auditStmt = $pdo->prepare('SELECT * FROM system_audit WHERE user_id = ? ORDER BY created_at DESC');
    $auditStmt->execute([$userId]);
    $export['audit_trail'] = $auditStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $GLOBALS['__audit_logged'] = true;
    audit_log($pdo, 'user.data_export', 'user', $userId, [
        'audit_trail_count' => count($export['audit_trail']),
    ]);

    $filename = 'pa-data-export-' . date('Y-m-d') . '.json';
    $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new Exception('Failed to encode export data to JSON');
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');

    echo $json;
    exit;
} catch (Throwable $e) {
    @error_log('[data_export] Failed for user ' . $userId . ': ' . $e->getMessage());
    header('Location: /?page=account/data-export&error=' . rawurlencode('Unable to generate data export. Please try again.'));
    exit;
}
