<?php
// src/controllers/auth/admin_2fa_disable.php
// Admin-only: disable 2FA for a specific user

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/acl.php';

// Require the dedicated security-management capability.
$actorId = (int)($_SESSION['user']['id'] ?? 0);
if ($actorId < 1 || !user_can($pdo, $actorId, '2fa.manage')) {
    header('Location: /?page=login');
    exit;
}

// Verify CSRF
$token = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
    header('Location: /?page=accounts&error=' . urlencode('Invalid request'));
    exit;
}

$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: /?page=accounts&error=' . urlencode('Invalid user ID'));
    exit;
}

try {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM user_2fa WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('UPDATE users SET auth_version = auth_version + 1 WHERE id = ?')->execute([$userId]);
    audit_log($pdo, 'auth.totp_admin_reset', 'user', $userId, [], $actorId);
    $pdo->commit();
    header('Location: /?page=account-edit&id=' . $userId . '&success=2fa_disabled');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Failed to disable 2FA'));
}
exit;
