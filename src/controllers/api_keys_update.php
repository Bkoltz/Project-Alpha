<?php
// src/controllers/api_keys_update.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/api_keys_schema.php';
require_once __DIR__ . '/../utils/api_scopes.php';
require_once __DIR__ . '/../utils/audit.php';

if (empty($_SESSION['user']) || (($_SESSION['user']['role'] ?? 'user') !== 'admin')) {
  header('Location: /?page=api-keys&error=' . urlencode('Only admins can update API keys'));
  exit;
}

$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$allowedIps = trim((string)($_POST['allowed_ips'] ?? '')) ?: null;
$scopes = api_normalize_scopes($_POST['scopes'] ?? []);
$returnToEdit = (string)($_POST['return_to'] ?? '') === 'edit';
$redirectBase = $returnToEdit && $id > 0 ? '/?page=api-keys-edit&id=' . $id : '/?page=api-keys';

if ($id <= 0) {
  header('Location: /?page=api-keys&error=' . urlencode('Invalid key'));
  exit;
}
if ($name === '') {
  header('Location: ' . $redirectBase . '&error=' . urlencode('Name is required'));
  exit;
}
if (!$scopes) {
  header('Location: ' . $redirectBase . '&error=' . urlencode('Select at least one API scope'));
  exit;
}

try {
  pa_ensure_api_keys_schema($pdo);
  $pdo->beginTransaction();
  $stmt = $pdo->prepare('UPDATE api_keys SET name = ?, scopes = ?, allowed_ips = ? WHERE id = ? AND revoked_at IS NULL');
  $stmt->execute([$name, api_scopes_to_storage($scopes), $allowedIps, $id]);
  if ($stmt->rowCount() < 1) {
    $exists = $pdo->prepare('SELECT 1 FROM api_keys WHERE id = ? AND revoked_at IS NULL');
    $exists->execute([$id]);
    if (!$exists->fetchColumn()) {
      if ($pdo->inTransaction()) { $pdo->rollBack(); }
      header('Location: ' . $redirectBase . '&error=' . urlencode('API key was not updated'));
      exit;
    }
  }
  $shouldDisableAlphaLedger = $scopes !== ['alphaledger.sync'];
  if (!$shouldDisableAlphaLedger && $allowedIps === null) {
    $policyStmt = $pdo->prepare('SELECT allow_unrestricted_key FROM alphaledger_policy WHERE singleton=1 AND approved_api_key_id=? AND enabled=1');
    $policyStmt->execute([$id]);
    $policyAllowance = $policyStmt->fetchColumn();
    $shouldDisableAlphaLedger = $policyAllowance !== false && !(bool)$policyAllowance;
  }
  if ($shouldDisableAlphaLedger) {
    $disabled = $pdo->prepare('UPDATE alphaledger_policy SET enabled=0,disabled_by=?,disabled_at=UTC_TIMESTAMP() WHERE singleton=1 AND approved_api_key_id=? AND enabled=1');
    $disabled->execute([(int)$_SESSION['user']['id'], $id]);
    if ($disabled->rowCount() > 0) {
      $pdo->prepare("UPDATE alphaledger_installations SET status='disabled' WHERE api_key_id=?")->execute([$id]);
      audit_log($pdo, 'alphaledger.policy_disabled', 'api_key', $id, ['reason' => 'approved_key_scope_changed']);
    }
  }
  $pdo->commit();
  header('Location: ' . $redirectBase . '&updated=1');
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  @error_log('[api_keys_update] Failed to update API key: ' . $e->getMessage());
  header('Location: ' . $redirectBase . '&error=' . urlencode('Failed to update key'));
  exit;
}
