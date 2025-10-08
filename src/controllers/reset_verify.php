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
// Normalize token: allow users to enter with spaces/dashes; prefer 6-digit numeric if applicable
$numeric = preg_replace('/\D+/', '', $token);
if (is_string($numeric) && strlen($numeric) === 6) {
  $token = $numeric;
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

  // Validate token: match user, not used, not expired (<= now), attempts < 3
  $st2 = $pdo->prepare('SELECT id, expires_at, used, attempts FROM password_resets WHERE user_id=? AND token=? ORDER BY id DESC LIMIT 1');
  $st2->execute([$uid, $token]);
  $row = $st2->fetch(PDO::FETCH_ASSOC);
  if (!$row) { throw new Exception('badtoken'); }
  $rid = (int)$row['id'];
  $attempts = (int)($row['attempts'] ?? 0);
  if ((int)$row['used'] === 1) { throw new Exception('used'); }
  if (strtotime((string)$row['expires_at']) < time()) { throw new Exception('expired'); }
  if ($attempts >= 3) { throw new Exception('locked'); }

  // Mark token used and allow setting password in next step
  $pdo->prepare('UPDATE password_resets SET used=1 WHERE id=?')->execute([$rid]);
  $_SESSION['reset_user_id'] = $uid;
  header('Location: /?page=reset-new&email=' . urlencode($email));
  exit;
} catch (Throwable $e) {
  // Increment attempts if a token row exists for this email+token and not used
  try {
    $st3 = $pdo->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE user_id = (SELECT id FROM users WHERE email=? LIMIT 1) AND token=? AND used=0 ORDER BY id DESC LIMIT 1');
    $st3->execute([$email, $token]);
    // Lock if attempts >= 3
    $st4 = $pdo->prepare('UPDATE password_resets SET used=1 WHERE user_id = (SELECT id FROM users WHERE email=? LIMIT 1) AND token=? AND attempts >= 3');
    $st4->execute([$email, $token]);
  } catch (Throwable $e2) { /* ignore */ }
  header('Location: /?page=reset-verify&email=' . urlencode($email) . '&error=' . urlencode('Invalid or expired code'));
  exit;
}
