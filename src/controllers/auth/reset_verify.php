<?php
// src/controllers/auth/reset_verify.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/logger.php';
require_once __DIR__ . '/../../utils/password_reset_tokens.php';
require_once __DIR__ . '/../../config/app.php';

// CSRF check (Symfony-backed)
require_once __DIR__ . '/../../utils/csrf_sf.php';
$submitted = (string)($_POST['_token'] ?? ($_POST['csrf'] ?? ''));
if (!csrf_sf_is_valid('reset_verify', $submitted)) {
  header('Location: /?page=reset-verify&error=' . urlencode('Invalid request'));
  exit;
}

$email = trim((string)($_POST['email'] ?? ''));
$token = password_reset_normalize_token((string)($_POST['token'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $token === '') {
  header('Location: /?page=reset-verify&email=' . urlencode($email) . '&error=' . urlencode('Enter your email and code'));
  exit;
}

try {
  $uid = password_reset_verify_and_consume($pdo, $email, $token);
  $_SESSION['reset_user_id'] = $uid;
  $version = $pdo->prepare('SELECT auth_version FROM users WHERE id = ? AND is_disabled = 0 AND deleted_at IS NULL');
  $version->execute([$uid]);
  $_SESSION['reset_auth_version'] = (int)$version->fetchColumn();
  $_SESSION['reset_verified_at'] = time();
  header('Location: /?page=reset-new&email=' . urlencode($email));
  exit;
} catch (Throwable $e) {
  header('Location: /?page=reset-verify&email=' . urlencode($email) . '&error=' . urlencode('Invalid or expired code'));
  exit;
}
