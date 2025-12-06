<?php
// src/controllers/accounts/accounts_reset_password.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

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
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([$passwordHash, $userId]);
    
    // TODO: If force_reset is true, set flag in database for forced password change
    // This would require adding a force_password_reset column to users table
    
    header('Location: /?page=accounts&action=edit&id=' . $userId . '&pwd_reset=1');
} catch (PDOException $e) {
    error_log('Failed to reset password: ' . $e->getMessage());
    header('Location: /?page=accounts&action=edit&id=' . $userId . '&error=' . urlencode('Failed to reset password'));
}
exit;
