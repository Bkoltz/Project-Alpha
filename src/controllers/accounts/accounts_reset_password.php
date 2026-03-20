<?php
// src/controllers/accounts/accounts_reset_password.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

// Ensure user is logged in and is an admin
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /?page=login');
    exit;
}

csrf_verify_post_or_redirect('accounts');

$userId = (int)($_POST['user_id'] ?? 0);
$newPassword = $_POST['new_password'] ?? '';
$forceReset = !empty($_POST['force_reset']);

// Validation
if ($userId <= 0) {
    header('Location: /?page=accounts&error=' . urlencode('Invalid user ID'));
    exit;
}

if (empty($newPassword) || strlen($newPassword) < 8) {
    header('Location: /?page=accounts&action=edit&id=' . $userId . '&error=' . urlencode('Password must be at least 8 characters'));
    exit;
}

// Hash new password
$passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

// Update password
try {
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, force_password_reset = ? WHERE id = ?');
    $stmt->execute([$passwordHash, $forceReset ? 1 : 0, $userId]);
    
    header('Location: /?page=accounts&action=edit&id=' . $userId . '&pwd_reset=1');
} catch (PDOException $e) {
    error_log('Failed to reset password: ' . $e->getMessage());
    header('Location: /?page=accounts&action=edit&id=' . $userId . '&error=' . urlencode('Failed to reset password'));
}
exit;
