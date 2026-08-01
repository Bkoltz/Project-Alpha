<?php
// src/controllers/accounts/accounts_reset_password.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/password_policy.php';

// Ensure user is logged in and is an admin
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /?page=login');
    exit;
}


$userId = (int)($_POST['user_id'] ?? 0);
$newPassword = $_POST['new_password'] ?? '';
$forceReset = !empty($_POST['force_reset']);

// Validation
if ($userId <= 0) {
    header('Location: /?page=accounts&error=' . urlencode('Invalid user ID'));
    exit;
}

$pwdErr = password_policy_error((string)$newPassword);
if ($pwdErr !== null) {
    header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode($pwdErr));
    exit;
}

// Hash new password
$passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

// Update password
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, force_password_reset = ?, auth_version = auth_version + 1 WHERE id = ?');
    $stmt->execute([$passwordHash, $forceReset ? 1 : 0, $userId]);
    $pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0')->execute([$userId]);
    $pdo->prepare('DELETE FROM trusted_devices WHERE user_id = ?')->execute([$userId]);
    
    audit_log($pdo, 'user.password_reset_by_admin', 'user', $userId);
    $pdo->commit();
    App\Security\SessionRevocation::revokeUserSessions($pdo, $userId);
    if ($userId === (int)$_SESSION['user']['id']) {
        $_SESSION = [];
        session_destroy();
        header('Location: /?page=login&error=' . urlencode('Password reset. Please sign in again.'));
        exit;
    }
    header('Location: /?page=account-edit&id=' . $userId . '&success=pwd_reset');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Failed to reset password: ' . $e->getMessage());
    header('Location: /?page=accounts&action=edit&id=' . $userId . '&error=' . urlencode('Failed to reset password'));
}
exit;
