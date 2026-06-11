<?php
// src/controllers/accounts/accounts_create.php
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


$email = trim($_POST['email'] ?? '');
$username = trim($_POST['username'] ?? '');
$role = trim($_POST['role'] ?? 'user');
$password = $_POST['password'] ?? '';
$forceReset = !empty($_POST['force_reset']);

// Validation
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: /?page=accounts&error=' . urlencode('Invalid email address'));
    exit;
}

$pwdErr = password_policy_error((string)$password);
if ($pwdErr !== null) {
    header('Location: /?page=accounts&error=' . urlencode($pwdErr));
    exit;
}

if (!in_array($role, ['user', 'admin'])) {
    $role = 'user';
}

// Check if email already exists
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    header('Location: /?page=accounts&error=' . urlencode('Email already exists'));
    exit;
}

// Hash password
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// Insert user
try {
    $stmt = $pdo->prepare('INSERT INTO users (email, username, password_hash, role, force_password_reset) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$email, $username ?: null, $passwordHash, $role, $forceReset ? 1 : 0]);
    
    audit_log($pdo, 'user.create', 'user', (int)$pdo->lastInsertId(), ['email' => $email, 'role' => $role]);
    header('Location: /?page=accounts&created=1');
} catch (PDOException $e) {
    error_log('Failed to create user: ' . $e->getMessage());
    header('Location: /?page=accounts&error=' . urlencode('Failed to create user'));
}
exit;
