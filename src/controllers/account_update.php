<?php
// src/controllers/account_update.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';

if (empty($_SESSION['user'])) {
  header('Location: /?page=login');
  exit;
}

// CSRF check
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
  header('Location: /?page=account&pwd_error=' . urlencode('Invalid request'));
  exit;
}

$uid = (int)($_SESSION['user']['id'] ?? 0);
$current = (string)($_POST['current_password'] ?? '');
$new = (string)($_POST['new_password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');

if ($uid <= 0 || $new === '' || $new !== $confirm) {
  header('Location: /?page=account&pwd_error=' . urlencode('Passwords do not match'));
  exit;
}

try {
  $st = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
  $st->execute([$uid]);
  $hash = (string)$st->fetchColumn();
  if ($hash === '' || !password_verify($current, $hash)) {
    header('Location: /?page=account&pwd_error=' . urlencode('Current password is incorrect'));
    exit;
  }
  $newHash = password_hash($new, PASSWORD_DEFAULT);
  $up = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
  $up->execute([$newHash, $uid]);
  header('Location: /?page=account&pwd=1');
  exit;
} catch (Throwable $e) {
  header('Location: /?page=account&pwd_error=' . urlencode('Failed to update password'));
  exit;
}
