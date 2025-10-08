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
// Normalize token: allow users to enter with spaces/dashes; accept 4-6 digit numeric, pad to 6
$numeric = preg_replace('/\D+/', '', $token);
if (is_string($numeric) && strlen($numeric) > 0) {
  // Use last 6 digits in case of long paste, and left-pad to preserve leading zeros
  $token = str_pad(substr($numeric, -6), 6, '0', STR_PAD_LEFT);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $token === '') {
  header('Location: /?page=reset-verify&email=' . urlencode($email) . '&error=' . urlencode('Enter your email and code'));
  exit;
}

try {
  // Get user id
  $st = $pdo->prepare('SELECT id FROM users WHERE email=?');
  $st->execute([$email]);
  $uid = (int)($st->fetchColumn() ?: 0);
  if ($uid <= 0) { throw new Exception('notfound'); }

  // Check if attempts column exists
  $hasAttempts = false;
  try {
    $chk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='password_resets' AND COLUMN_NAME='attempts'");
    $chk->execute();
    $hasAttempts = ((int)$chk->fetchColumn()) === 1;
  } catch (Throwable $e) { $hasAttempts = false; }

  // Fetch most recent unused token for this user
  if ($hasAttempts) {
    $st2 = $pdo->prepare('SELECT id, token, expires_at, used, attempts FROM password_resets WHERE user_id=? AND used=0 ORDER BY id DESC LIMIT 1');
  } else {
    $st2 = $pdo->prepare('SELECT id, token, expires_at, used FROM password_resets WHERE user_id=? AND used=0 ORDER BY id DESC LIMIT 1');
  }
  $st2->execute([$uid]);
  $row = $st2->fetch(PDO::FETCH_ASSOC);
  if (!$row) { throw new Exception('badtoken'); }
  $rid = (int)$row['id'];
  $stored = (string)($row['token'] ?? '');
  $storedNorm = preg_replace('/\D+/', '', $stored);
  $attempts = $hasAttempts ? (int)($row['attempts'] ?? 0) : 0;
  if ((int)$row['used'] === 1) { throw new Exception('used'); }
  if (strtotime((string)$row['expires_at']) < time()) { throw new Exception('expired'); }
  if ($hasAttempts && $attempts >= 3) { throw new Exception('locked'); }
  if (!hash_equals((string)$token, (string)$stored) && !($storedNorm && hash_equals((string)$token, (string)$storedNorm))) {
    throw new Exception('badtoken');
  }

  // Mark token used and allow setting password in next step
  $pdo->prepare('UPDATE password_resets SET used=1 WHERE id=?')->execute([$rid]);
  $_SESSION['reset_user_id'] = $uid;
  header('Location: /?page=reset-new&email=' . urlencode($email));
  exit;
} catch (Throwable $e) {
  // Increment attempts if supported and a token row exists
  try {
    $chk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='password_resets' AND COLUMN_NAME='attempts'");
    $chk->execute();
    $hasAttempts = ((int)$chk->fetchColumn()) === 1;
    if ($hasAttempts) {
      $st3 = $pdo->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE user_id = (SELECT id FROM users WHERE email=? LIMIT 1) AND used=0 ORDER BY id DESC LIMIT 1');
      $st3->execute([$email]);
      // Lock if attempts >= 3
      $st4 = $pdo->prepare('UPDATE password_resets SET used=1 WHERE user_id = (SELECT id FROM users WHERE email=? LIMIT 1) AND attempts >= 3');
      $st4->execute([$email]);
    }
  } catch (Throwable $e2) { /* ignore */ }
  header('Location: /?page=reset-verify&email=' . urlencode($email) . '&error=' . urlencode('Invalid or expired code'));
  exit;
}
