<?php
// src/controllers/reset_update.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';

$csrf = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
  header('Location: /?page=reset-new&error=' . urlencode('Invalid request'));
  exit;
}

$uid = isset($_SESSION['reset_user_id']) ? (int)$_SESSION['reset_user_id'] : 0;
$email = isset($_POST['email']) ? (string)$_POST['email'] : '';
$new = (string)($_POST['new_password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');

if ($uid <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($new) < 8 || $new !== $confirm) {
  header('Location: /?page=reset-new&email=' . urlencode($email) . '&error=' . urlencode('Invalid input'));
  exit;
}

try {
  $hash = password_hash($new, PASSWORD_DEFAULT);
  $st = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
  $st->execute([$hash, $uid]);
  unset($_SESSION['reset_user_id']);
  header('Location: /?page=login&pwd_reset=1');
  exit;
} catch (Throwable $e) {
  header('Location: /?page=reset-new&email=' . urlencode($email) . '&error=' . urlencode('Could not update password'));
  exit;
}