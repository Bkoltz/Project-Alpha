<?php
// src/controllers/auth/reset_update.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/password_policy.php';
require_once __DIR__ . '/../../utils/password_reset_tokens.php';

require_once __DIR__ . '/../../utils/csrf_sf.php';
$submitted = (string)($_POST['_token'] ?? ($_POST['csrf'] ?? ''));
if (!csrf_sf_is_valid('reset_update', $submitted)) {
  header('Location: /?page=reset-new&error=' . urlencode('Invalid request'));
  exit;
}

$uid = isset($_SESSION['reset_user_id']) ? (int)$_SESSION['reset_user_id'] : 0;
$email = isset($_POST['email']) ? (string)$_POST['email'] : '';
$new = (string)($_POST['new_password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');
$token = isset($_POST['token']) ? password_reset_normalize_token((string)$_POST['token']) : '';

// Validate passwords before consuming any token. A typo in the new password
// fields should not burn an otherwise valid reset code.
if ($new !== $confirm) {
  header('Location: /?page=reset-new&email=' . urlencode($email) . '&error=' . urlencode('Invalid input'));
  exit;
}
$pwdErr = password_policy_error($new);
if ($pwdErr !== null) {
  header('Location: /?page=reset-new&email=' . urlencode($email) . '&error=' . urlencode($pwdErr));
  exit;
}

// If no session-bound uid, allow token-based verification in this step (single-step reset)
if ($uid <= 0) {
  // Validate basics first
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: /?page=reset-new&email=' . urlencode($email) . '&error=' . urlencode('Enter your email and code'));
    exit;
  }
  if ($token === '') {
    header('Location: /?page=reset-new&email=' . urlencode($email) . '&error=' . urlencode('Enter your email and code'));
    exit;
  }
  try {
    $uid = password_reset_verify_and_consume($pdo, $email, $token);
  } catch (Throwable $e) {
    header('Location: /?page=reset-new&email=' . urlencode($email) . '&error=' . urlencode('Invalid or expired code'));
    exit;
  }
}

if ($uid <= 0) {
  header('Location: /?page=reset-new&email=' . urlencode($email) . '&error=' . urlencode('Invalid input'));
  exit;
}

try {
  $hash = password_hash($new, PASSWORD_DEFAULT);
  $st = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
  $st->execute([$hash, $uid]);
  audit_log($pdo, 'user.password_reset_via_token', 'user', $uid, [], $uid);
  unset($_SESSION['reset_user_id']);
  header('Location: /?page=login&pwd_reset=1');
  exit;
} catch (Throwable $e) {
  header('Location: /?page=reset-new&email=' . urlencode($email) . '&error=' . urlencode('Could not update password'));
  exit;
}
