<?php
// src/controllers/accounts/accounts_delete.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

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

// Delete user (user_organizations will cascade due to FK)
try {
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    
    header('Location: /?page=accounts&deleted=1');
} catch (PDOException $e) {
    error_log('Failed to delete user: ' . $e->getMessage());
    header('Location: /?page=accounts&error=' . urlencode('Failed to delete user'));
}
exit;
