<?php
// src/controllers/legal/tos_accept.php
// Handles ToS acceptance from the login gate
// Records tos_accepted_at timestamp and redirects to dashboard

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/audit.php';

// Must be logged in
$userId = $_SESSION['user']['id'] ?? null;
if (!$userId) {
    header('Location: /?page=login');
    exit;
}

// CSRF validation
$submitted = $_POST['_token'] ?? '';
csrf_sf_verify_or_redirect('auth', 'legal/tos-accept', is_string($submitted) ? $submitted : '');

// Must have accepted the checkbox
if (empty($_POST['tos_accepted'])) {
    header('Location: /?page=legal/tos-accept&error=' . urlencode('You must accept the Terms of Service to continue'));
    exit;
}

try {
    $stmt = $pdo->prepare('UPDATE users SET tos_accepted_at = NOW() WHERE id = ?');
    $stmt->execute([$userId]);
    audit_log($pdo, 'user.tos_accepted', 'user', $userId);
    header('Location: /');
    exit;
} catch (Throwable $e) {
    error_log('[tos_accept] Failed: ' . $e->getMessage());
    header('Location: /?page=legal/tos-accept&error=' . urlencode('Failed to record acceptance. Please try again.'));
    exit;
}