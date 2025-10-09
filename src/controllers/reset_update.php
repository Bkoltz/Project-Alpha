<?php
// src/controllers/reset_update.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

require_once __DIR__ . '/../utils/csrf_sf.php';
$submitted = (string)($_POST['_token'] ?? ($_POST['csrf'] ?? ''));
if (!csrf_sf_is_valid('reset_update', $submitted)) {
  header('Location: /?page=reset-new&error=' . urlencode('Invalid request'));
  exit;
}

$uid = isset($_SESSION['reset_user_id']) ? (int)$_SESSION['reset_user_id'] : 0;
$email = isset($_POST['email']) ? (string)$_POST['email'] : '';
$new = (string)($_POST['new_password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');
$token = isset($_POST['token']) ? trim((string)$_POST['token']) : '';

// If no session-bound uid, allow token-based verification in this step (single-step reset)
if ($uid <= 0) {
  // Validate basics first
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: /?page=reset-new&email=' . urlencode($email) . '&error=' . urlencode('Enter your email and code'));
    exit;
  }
  $numeric = preg_replace('/\D+/', '', $token);
  if (is_string($numeric) && strlen($numeric) > 0) { $token = str_pad(substr($numeric, -6), 6, '0', STR_PAD_LEFT); }
  if ($token === '') {
    header('Location: /?page=reset-new&email=' . urlencode($email) . '&error=' . urlencode('Enter your email and code'));
    exit;
  }
  try {
    // Resolve user
    $st = $pdo->prepare('SELECT id FROM users WHERE email=?');
    $st->execute([$email]);
    $uid = (int)($st->fetchColumn() ?: 0);
    if ($uid <= 0) { throw new Exception('nouser'); }

    // Prefer matching token exactly, then fallback to latest unused
    $row = null;
    try {
      $st2a = $pdo->prepare('SELECT id, token, expires_at, used FROM password_resets WHERE user_id=? AND used=0 AND token=? ORDER BY id DESC LIMIT 1');
      $st2a->execute([$uid, $token]);
      $row = $st2a->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$row && ctype_digit($token)) {
        $st2b = $pdo->prepare("SELECT id, token, expires_at, used FROM password_resets WHERE user_id=? AND used=0 AND REPLACE(REPLACE(token,'-',''),' ','')=? ORDER BY id DESC LIMIT 1");
        $st2b->execute([$uid, $token]);
        $row = $st2b->fetch(PDO::FETCH_ASSOC) ?: null;
      }
    } catch (Throwable $e) { /* ignore */ }
    if (!$row) {
      $st2 = $pdo->prepare('SELECT id, token, expires_at, used FROM password_resets WHERE user_id=? AND used=0 ORDER BY id DESC LIMIT 1');
      $st2->execute([$uid]);
      $row = $st2->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) { throw new Exception('badtoken'); }
    if ((int)$row['used'] === 1) { throw new Exception('used'); }
    if (strtotime((string)$row['expires_at']) < time()) { throw new Exception('expired'); }

    // Check match (normalize stored)
    $stored = (string)($row['token'] ?? '');
    $storedNorm = preg_replace('/\D+/', '', $stored);
    $okMatch = hash_equals((string)$token, (string)$stored) || ($storedNorm && hash_equals((string)$token, (string)$storedNorm));
    if (!$okMatch) { throw new Exception('badtoken'); }

    // Mark used (invalidate token)
    $pdo->prepare('UPDATE password_resets SET used=1 WHERE id=?')->execute([(int)$row['id']]);
  } catch (Throwable $e) {
    header('Location: /?page=reset-new&email=' . urlencode($email) . '&error=' . urlencode('Invalid or expired code'));
    exit;
  }
}

// Validate passwords
if ($uid <= 0 || strlen($new) < 8 || $new !== $confirm) {
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
