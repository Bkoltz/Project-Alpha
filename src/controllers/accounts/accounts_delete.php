<?php
// src/controllers/accounts/accounts_delete.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';

// Ensure user is logged in and is an admin
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /?page=login');
    exit;
}


$userId = (int)($_POST['user_id'] ?? 0);

// Validation
if ($userId <= 0) {
    header('Location: /?page=accounts&error=' . urlencode('Invalid user ID'));
    exit;
}

// Prevent deleting yourself
if ($userId == ($_SESSION['user']['id'] ?? 0)) {
    header('Location: /?page=accounts&error=' . urlencode('Cannot delete your own account'));
    exit;
}

// Protect the seeded admin account (id=1)
if ($userId === 1) {
    header('Location: /?page=accounts&error=' . urlencode('The default admin account cannot be deleted'));
    exit;
}

// Prevent deleting other admin accounts
$stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if ($user && $user['role'] === 'admin') {
    header('Location: /?page=accounts&error=' . urlencode('Cannot delete admin accounts'));
    exit;
}

// Delete user (explicit cleanup to avoid surprise cascades)
try {
    // Remove user-specific auth data first
    $pdo->prepare('DELETE FROM user_2fa WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM login_2fa_attempts WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM user_organizations WHERE user_id = ?')->execute([$userId]);

    // Set creator references to NULL so business records remain intact
    $pdo->prepare('UPDATE receipts SET uploaded_by = NULL WHERE uploaded_by = ?')->execute([$userId]);
    $pdo->prepare('UPDATE form_documents SET uploaded_by = NULL WHERE uploaded_by = ?')->execute([$userId]);
    $pdo->prepare('UPDATE form_categories SET created_by = NULL WHERE created_by = ?')->execute([$userId]);

    // Delete the user account
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$userId]);

    audit_log($pdo, 'user.delete', 'user', $userId);
    header('Location: /?page=accounts&deleted=1');
} catch (PDOException $e) {
    error_log('Failed to delete user: ' . $e->getMessage());
    header('Location: /?page=accounts&error=' . urlencode('Failed to delete user'));
}
exit;
