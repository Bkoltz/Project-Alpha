<?php
// src/controllers/account/account_delete.php
// GDPR/CCPA "Right to Erasure" — permanent account and data deletion.
// Router calls this on POST to /?page=account/delete

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';

$userId = !empty($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;

// 1. Require authenticated session
if ($userId <= 0) {
    header('Location: /?page=login');
    exit;
}

// 2. Require CSRF validation
if (!csrf_validate()) {
    header('Location: /?page=account/delete&error=' . rawurlencode('Invalid request (CSRF)'));
    exit;
}

// 3. Require explicit confirmation and password
$confirm = isset($_POST['confirm']) ? trim((string)$_POST['confirm']) : '';
$password = isset($_POST['password']) ? (string)$_POST['password'] : '';

if ($confirm !== 'DELETE MY ACCOUNT') {
    header('Location: /?page=account/delete&error=' . rawurlencode('Please type DELETE MY ACCOUNT to confirm.'));
    exit;
}

if ($password === '') {
    header('Location: /?page=account/delete&error=' . rawurlencode('Password is required.'));
    exit;
}

// Verify the current user's password hash before proceeding
try {
    $stmt = $pdo->prepare('SELECT email, password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        header('Location: /?page=account/delete&error=' . rawurlencode('Incorrect password.'));
        exit;
    }
} catch (Throwable $e) {
    error_log('[account_delete] failed to verify user: ' . $e->getMessage());
    header('Location: /?page=account/delete&error=' . rawurlencode('Unable to verify account. Please try again.'));
    exit;
}

// 4. Log deletion request to audit log BEFORE deleting
audit_log($pdo, 'user.account_deleted', 'user', $userId);

// 5. Delete all user data inside a transaction
try {
    $pdo->beginTransaction();

    // User-specific data. Business/customer records remain intact.
    $pdo->prepare('DELETE FROM system_audit WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM user_2fa WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM trusted_devices WHERE user_id = ?')->execute([$userId]);

    // Re-insert a single audit record documenting the erasure after clearing system_audit
    audit_log($pdo, 'user.account_deleted', 'user', $userId);

    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[account_delete] deletion failed for user ' . $userId . ': ' . $e->getMessage());
    header('Location: /?page=account/delete&error=' . rawurlencode('Account deletion failed. Please contact support.'));
    exit;
}

// 6. On success: destroy session and redirect to public confirmation page
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
}
session_destroy();

header('Location: /?page=account-deleted');
exit;
