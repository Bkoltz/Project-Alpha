<?php
// src/controllers/auth/account_update.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/password_policy.php';
require_once __DIR__ . '/../../utils/password_reset_tokens.php';

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

$pwdErr = password_policy_error($new);
if ($pwdErr !== null) {
  header('Location: /?page=account&pwd_error=' . urlencode($pwdErr));
  exit;
}

try {
  $pdo->beginTransaction();
  $st = $pdo->prepare('SELECT password_hash, auth_version FROM users WHERE id=? FOR UPDATE');
  $st->execute([$uid]);
  $lockedUser = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $hash = (string)($lockedUser['password_hash'] ?? '');
  if ($hash === '' || !password_verify($current, $hash)) {
    $pdo->rollBack();
    header('Location: /?page=account&pwd_error=' . urlencode('Current password is incorrect'));
    exit;
  }
  $newHash = password_hash($new, PASSWORD_DEFAULT);
  $up = $pdo->prepare('UPDATE users SET password_hash=?, force_password_reset=0, auth_version=auth_version+1 WHERE id=?');
  $up->execute([$newHash, $uid]);
  password_reset_revoke_for_user($pdo, $uid);
  $pdo->prepare('DELETE FROM trusted_devices WHERE user_id=?')->execute([$uid]);
  $version = $pdo->prepare('SELECT auth_version, totp_reenroll_required FROM users WHERE id=?');
  $version->execute([$uid]);
  $recoveryState = $version->fetch(PDO::FETCH_ASSOC) ?: [];
  audit_log($pdo, 'user.password_changed', 'user', $uid);
  $pdo->commit();
  $_SESSION['user']['auth_version'] = (int)($recoveryState['auth_version'] ?? 0);
  App\Security\SessionRevocation::revokeUserSessions($pdo, $uid, session_id());
  App\Security\SessionPolicy::rotateAuthenticatedId();
  if ((int)($recoveryState['totp_reenroll_required'] ?? 0) === 1) {
    header('Location: /?page=2fa-setup&required=1&recovery=1');
    exit;
  }
  header('Location: /?page=account&pwd=1');
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  header('Location: /?page=account&pwd_error=' . urlencode('Failed to update password'));
  exit;
}
