<?php
// src/controllers/accounts/accounts_update.php
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
$email = trim($_POST['email'] ?? '');
$username = trim($_POST['username'] ?? '');
$role = trim($_POST['role'] ?? 'user');
$forceReset = !empty($_POST['force_reset']);

// Validation
if ($userId <= 0) {
    header('Location: /?page=accounts&error=' . urlencode('Invalid user ID'));
    exit;
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: /?page=accounts&action=edit&id=' . $userId . '&error=' . urlencode('Invalid email address'));
    exit;
}

if (!in_array($role, ['user', 'admin'])) {
    $role = 'user';
}

// Check if email is taken by another user
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
$stmt->execute([$email, $userId]);
if ($stmt->fetch()) {
    header('Location: /?page=accounts&action=edit&id=' . $userId . '&error=' . urlencode('Email already exists'));
    exit;
}

// Update user
try {
    $stmt = $pdo->prepare('UPDATE users SET email = ?, username = ?, role = ?, force_password_reset = ? WHERE id = ?');
    $stmt->execute([$email, $username ?: null, $role, $forceReset ? 1 : 0, $userId]);
    
    header('Location: /?page=accounts&updated=1');
} catch (PDOException $e) {
    error_log('Failed to update user: ' . $e->getMessage());
    header('Location: /?page=accounts&action=edit&id=' . $userId . '&error=' . urlencode('Failed to update user'));
}
exit;
