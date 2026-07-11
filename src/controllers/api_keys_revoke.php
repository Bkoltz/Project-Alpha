<?php
// src/controllers/api_keys_revoke.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/api_keys_schema.php';
require_once __DIR__ . '/../utils/audit.php';

if (empty($_SESSION['user']) || (($_SESSION['user']['role'] ?? 'user') !== 'admin')) {
  header('Location: /?page=api-keys&error=' . urlencode('Only admins can revoke API keys'));
  exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: /?page=api-keys&error=' . urlencode('Invalid key')); exit; }

try {
  pa_ensure_api_keys_schema($pdo);
  $pdo->beginTransaction();
  $pdo->prepare('UPDATE api_keys SET revoked_at=NOW() WHERE id=?')->execute([$id]);
  $disabled = $pdo->prepare('UPDATE alphaledger_policy SET enabled=0,disabled_by=?,disabled_at=UTC_TIMESTAMP() WHERE singleton=1 AND approved_api_key_id=? AND enabled=1');
  $disabled->execute([(int)$_SESSION['user']['id'], $id]);
  if ($disabled->rowCount() > 0) {
    $pdo->prepare("UPDATE alphaledger_installations SET status='disabled' WHERE api_key_id=?")->execute([$id]);
    audit_log($pdo, 'alphaledger.policy_disabled', 'api_key', $id, ['reason' => 'approved_key_revoked']);
  }
  $pdo->commit();
  header('Location: /?page=api-keys&revoked=1');
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  header('Location: /?page=api-keys&error=' . urlencode('Failed to revoke key'));
  exit;
}
