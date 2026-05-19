<?php
// src/controllers/auth/admin_2fa_disable.php
// Admin-only: disable 2FA for a specific user

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

// Require admin
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
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

// Protect the built-in admin
if ($userId === 1) {
    header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Cannot modify the default admin account'));
    exit;
}

try {
    $pdo->prepare('DELETE FROM user_2fa WHERE user_id = ?')->execute([$userId]);
    header('Location: /?page=account-edit&id=' . $userId . '&success=2fa_disabled');
} catch (Throwable $e) {
    header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Failed to disable 2FA'));
}
exit;
