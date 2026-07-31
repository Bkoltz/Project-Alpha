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
$expectedAuthVersion = 0;

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

if ($uid <= 0) {
  header('Location: /?page=reset-password&error=' . urlencode('Verify a new reset code before choosing a password.'));
  exit;
}

if ($uid > 0) {
  $verifiedAt = (int)($_SESSION['reset_verified_at'] ?? 0);
  $expectedAuthVersion = (int)($_SESSION['reset_auth_version'] ?? 0);
  $version = $pdo->prepare('SELECT auth_version FROM users WHERE id = ? AND is_disabled = 0 AND deleted_at IS NULL');
  $version->execute([$uid]);
  $currentAuthVersion = (int)$version->fetchColumn();
  if ($verifiedAt <= 0 || time() - $verifiedAt > 300 || $expectedAuthVersion < 1 || $currentAuthVersion !== $expectedAuthVersion) {
    unset($_SESSION['reset_user_id'], $_SESSION['reset_auth_version'], $_SESSION['reset_verified_at']);
    header('Location: /?page=reset-password&error=' . urlencode('That reset authorization expired. Request a new code.'));
    exit;
  }
}

try {
  $pdo->beginTransaction();
  $hash = password_hash($new, PASSWORD_DEFAULT);
  $st = $pdo->prepare('UPDATE users SET password_hash=?, force_password_reset=0, auth_version=auth_version+1 WHERE id=? AND auth_version=? AND is_disabled=0 AND deleted_at IS NULL');
  $st->execute([$hash, $uid, $expectedAuthVersion]);
  if ($st->rowCount() !== 1) {
    throw new RuntimeException('Reset authorization was revoked.');
  }
  password_reset_revoke_for_user($pdo, $uid);
  $pdo->prepare('DELETE FROM trusted_devices WHERE user_id=?')->execute([$uid]);
  audit_log($pdo, 'user.password_reset_via_token', 'user', $uid, [], $uid);
  $pdo->commit();
  App\Security\SessionRevocation::revokeUserSessions($pdo, $uid);
  unset($_SESSION['reset_user_id'], $_SESSION['reset_auth_version'], $_SESSION['reset_verified_at']);
  header('Location: /?page=login&pwd_reset=1');
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  header('Location: /?page=reset-new&email=' . urlencode($email) . '&error=' . urlencode('Could not update password'));
  exit;
}
