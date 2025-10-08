<?php
// src/controllers/reset_verify.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';

// CSRF check
$csrf = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
  header('Location: /?page=reset-verify&error=' . urlencode('Invalid request'));
  exit;
}

$email = trim((string)($_POST['email'] ?? ''));
$token = trim((string)($_POST['token'] ?? ''));
$new = (string)($_POST['new_password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $token === '' || strlen($new) < 8 || $new !== $confirm) {
  header('Location: /?page=reset-verify&email=' . urlencode($email) . '&error=' . urlencode('Invalid input'));
  exit;
}

try {
  // Get user id
  $st = $pdo->prepare('SELECT id FROM users WHERE email=?');
  $st->execute([$email]);
  $uid = (int)($st->fetchColumn() ?: 0);
  if ($uid <= 0) { throw new Exception('notfound'); }

  // Validate token: match user, not used, not expired (<= now)
  $st2 = $pdo->prepare('SELECT id, expires_at, used FROM password_resets WHERE user_id=? AND token=? ORDER BY id DESC LIMIT 1');
  $st2->execute([$uid, $token]);
  $row = $st2->fetch(PDO::FETCH_ASSOC);
  if (!$row) { throw new Exception('badtoken'); }
  if ((int)$row['used'] === 1) { throw new Exception('used'); }
  if (strtotime((string)$row['expires_at']) < time()) { throw new Exception('expired'); }

  // Update password
  $hash = password_hash($new, PASSWORD_DEFAULT);
  $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $uid]);
  // Mark token used
  $pdo->prepare('UPDATE password_resets SET used=1 WHERE id=?')->execute([(int)$row['id']]);

  header('Location: /?page=login&pwd_reset=1');
  exit;
} catch (Throwable $e) {
  header('Location: /?page=reset-verify&email=' . urlencode($email) . '&error=' . urlencode('Invalid or expired code'));
  exit;
}
