<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/api_scopes.php';
require_once __DIR__ . '/../../utils/alphaledger_integration.php';
require_once __DIR__ . '/../../utils/alphaledger_time_bridge.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
require_once __DIR__ . '/../../utils/two_factor_auth.php';

use App\Utils\TwoFactorAuth;

function pa_al_settings_redirect(string $kind, string $message): void
{
    header('Location: /?page=settings&tab=alphaledger&' . $kind . '=' . rawurlencode($message));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$userId = (int) ($_SESSION['user']['id'] ?? 0);
if ($userId < 1 || ($_SESSION['user']['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit;
}
$action = (string) ($_POST['action'] ?? '');
if ($action === 'sync-now') {
    if (!rate_limit_check($pdo, 'alphaledger_sync_user_' . $userId, 10, 300, false)) {
        pa_al_settings_redirect('error', 'Too many manual sync attempts. Wait five minutes and try again.');
    }
    try {
        $policy = pa_al_policy($pdo);
        if (empty($policy['enabled'])) {
            throw new DomainException('Enable AlphaLedger synchronization first.');
        }
        $stmt = $pdo->prepare("SELECT * FROM alphaledger_installations WHERE api_key_id=? AND status IN ('active','degraded') ORDER BY id DESC LIMIT 1");
        $stmt->execute([(int) $policy['approved_api_key_id']]);
        $installation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$installation) {
            throw new DomainException('AlphaLedger has not completed its installation handshake yet.');
        }
        pa_al_capture_owned_state($pdo, $installation);
        pa_al_refresh_assignments($pdo, $installation);
        pa_al_time_refresh_mapping_exceptions($pdo,$installation);
        $result = pa_al_deliver_pending($pdo, 100);
        $commands=pa_al_time_deliver_commands($pdo,100);
        audit_log($pdo, 'alphaledger.sync_requested', 'alphaledger_installation', (int) $installation['id'], $result);
        pa_al_settings_redirect('success', sprintf('Sync completed: events %d delivered/%d failed; commands %d delivered/%d failed.', $result['delivered'], $result['failed'],$commands['delivered'],$commands['failed']));
    } catch (Throwable $e) {
        pa_al_settings_redirect('error', $e->getMessage());
    }
}

if (!in_array($action, ['enable', 'disable', 'rotate-secret', 'purge-ledger'], true)) {
    pa_al_settings_redirect('error', 'Unknown integration action.');
}
if (!rate_limit_check($pdo, 'alphaledger_policy_user_' . $userId, 5, 900, false)) {
    pa_al_settings_redirect('error', 'Too many integration security actions. Wait 15 minutes and try again.');
}

$password = (string) ($_POST['admin_password'] ?? '');
$totpCode = trim((string) ($_POST['totp_code'] ?? ''));
$authStmt = $pdo->prepare('SELECT u.password_hash,t.secret,t.enabled FROM users u LEFT JOIN user_2fa t ON t.user_id=u.id WHERE u.id=? AND u.role="admin" AND u.is_disabled=0 AND u.deleted_at IS NULL LIMIT 1');
$authStmt->execute([$userId]);
$admin = $authStmt->fetch(PDO::FETCH_ASSOC);
if (!$admin || !password_verify($password, (string) $admin['password_hash'])) {
    audit_log($pdo, 'alphaledger.policy_reauth_failed', 'user', $userId, ['factor' => 'password']);
    pa_al_settings_redirect('error', 'Current administrator password is incorrect.');
}
if (empty($admin['enabled']) || empty($admin['secret'])) {
    pa_al_settings_redirect('error', 'Administrator TOTP 2FA must be enabled before AlphaLedger synchronization can change.');
}
if (!TwoFactorAuth::verifyCode($totpCode, (string) $admin['secret'], 1)) {
    audit_log($pdo, 'alphaledger.policy_reauth_failed', 'user', $userId, ['factor' => 'totp']);
    pa_al_settings_redirect('error', 'The administrator TOTP code is invalid.');
}

if ($action === 'purge-ledger') {
    $pdo->beginTransaction();
    try {
        $counts = [];
        foreach (['alphaledger_ledger_revisions','alphaledger_ledger_breaks','alphaledger_ledger_time_entries','alphaledger_ledger_assignments','alphaledger_ledger_projects','alphaledger_ledger_people','employee_pay_records','alphaledger_ledger_snapshots'] as $table) {
            $counts[$table] = $pdo->exec("DELETE FROM {$table}");
        }
        audit_log($pdo, 'alphaledger.ledger_purged', 'alphaledger_policy', 1, ['record_counts' => $counts]);
        $pdo->commit();
        pa_al_settings_redirect('success', 'The retained AlphaLedger operational Ledger was purged. Invoice-linked approved time was preserved as a PA financial record.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @error_log('[AlphaLedgerPurge] ' . $e->getMessage());
        pa_al_settings_redirect('error', 'Could not purge the retained AlphaLedger Ledger.');
    }
}

if ($action === 'rotate-secret') {
    try {
        $policy = pa_al_policy($pdo);
        if (empty($policy['enabled']) || empty($policy['approved_api_key_id'])) {
            throw new DomainException('AlphaLedger synchronization is not enabled.');
        }
        $secret = bin2hex(random_bytes(32));
        $encrypted = crypto_encrypt($secret);
        if (!$encrypted) {
            throw new RuntimeException('APP_ENCRYPTION_KEY is unavailable.');
        }
        $stmt = $pdo->prepare("UPDATE alphaledger_installations SET webhook_secret_enc=?,status='disabled',consecutive_failures=0 WHERE api_key_id=?");
        $stmt->execute([$encrypted, (int)$policy['approved_api_key_id']]);
        if ($stmt->rowCount() < 1) {
            throw new DomainException('No AlphaLedger installation exists yet.');
        }
        audit_log($pdo, 'alphaledger.webhook_secret_rotated', 'alphaledger_policy', 1);
        pa_al_settings_redirect('success', 'Webhook secret rotated. Reconnect from AlphaLedger before synchronization resumes.');
    } catch (Throwable $e) {
        pa_al_settings_redirect('error', $e->getMessage());
    }
}

if ($action === 'disable') {
    $pendingCommands=(int)$pdo->query("SELECT COUNT(*) FROM alphaledger_command_outbox WHERE state IN ('pending','attention')")->fetchColumn();
    if($pendingCommands>0) pa_al_settings_redirect('error','Resolve or explicitly cancel every pending AlphaLedger time command before disconnecting.');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE alphaledger_policy SET enabled=0,disabled_by=?,disabled_at=UTC_TIMESTAMP() WHERE singleton=1')->execute([$userId]);
        $pdo->exec("UPDATE alphaledger_installations SET status='disabled'");
        audit_log($pdo, 'alphaledger.policy_disabled', 'alphaledger_policy', 1);
        $pdo->commit();
        pa_al_settings_redirect('success', 'AlphaLedger synchronization is disabled. Historical imported records were preserved.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        pa_al_settings_redirect('error', 'Could not disable AlphaLedger synchronization.');
    }
}

if (empty($_POST['confirm_enable'])) {
    pa_al_settings_redirect('error', 'Confirm that AlphaLedger will become authoritative for time and pay accrual snapshots.');
}
$apiKeyId = (int) ($_POST['api_key_id'] ?? 0);
$callbackInput = trim((string) ($_POST['callback_url'] ?? ''));
try {
    $callback = pa_al_validate_callback_url($callbackInput);
} catch (DomainException $e) {
    pa_al_settings_redirect('error', $e->getMessage());
}
$keyStmt = $pdo->prepare('SELECT id,name,scopes,allowed_ips FROM api_keys WHERE id=? AND revoked_at IS NULL LIMIT 1');
$keyStmt->execute([$apiKeyId]);
$selectedKey = $keyStmt->fetch(PDO::FETCH_ASSOC);
if (!$selectedKey || api_normalize_scopes($selectedKey['scopes'] ?? '') !== ['alphaledger.sync']) {
    pa_al_settings_redirect('error', 'Select an active API key whose only scope is AlphaLedger integration. Full-access keys are not accepted.');
}
$keyHasIpAllowlist = trim((string) ($selectedKey['allowed_ips'] ?? '')) !== '';
$allowUnrestrictedKey = !$keyHasIpAllowlist && !empty($_POST['confirm_unrestricted_key']);
if (!$keyHasIpAllowlist && !$allowUnrestrictedKey) {
    pa_al_settings_redirect('error', 'Add an IP allowlist to the API key, or explicitly acknowledge that this deployment cannot use one.');
}

$callbackHash = hash('sha256', $callback);
$activeLocalTimer = $pdo->query("SELECT 1 FROM time_entries WHERE ended_at IS NULL AND COALESCE(source_system,'')<>'alphaledger' LIMIT 1")->fetchColumn();
if ($activeLocalTimer) {
    pa_al_settings_redirect('error', 'Stop every active PA timer before enabling AlphaLedger as the authoritative time system.');
}
$current = pa_al_policy($pdo);
$configurationChanged = (int) ($current['approved_api_key_id'] ?? 0) !== $apiKeyId
    || !hash_equals((string) ($current['approved_callback_hash'] ?? ''), $callbackHash);
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE alphaledger_policy SET enabled=1,approved_api_key_id=?,approved_callback_url=?,approved_callback_hash=?,allow_unrestricted_key=?,enabled_by=?,enabled_at=UTC_TIMESTAMP(),disabled_by=NULL,disabled_at=NULL WHERE singleton=1')
        ->execute([$apiKeyId, $callback, $callbackHash, $allowUnrestrictedKey ? 1 : 0, $userId]);
    if ($configurationChanged) {
        $pdo->exec("UPDATE alphaledger_installations SET status='disabled'");
    }
    audit_log($pdo, 'alphaledger.policy_enabled', 'alphaledger_policy', 1, [
        'api_key_id' => $apiKeyId,
        'callback_host' => (string) parse_url($callback, PHP_URL_HOST),
        'configuration_changed' => $configurationChanged,
        'ip_allowlist_configured' => $keyHasIpAllowlist,
        'unrestricted_key_acknowledged' => $allowUnrestrictedKey,
    ]);
    $pdo->commit();
    pa_al_settings_redirect('success', $configurationChanged
        ? 'AlphaLedger is authorized. Complete or repeat the connection from AlphaLedger.'
        : 'AlphaLedger authorization was reconfirmed.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @error_log('[AlphaLedgerPolicy] ' . $e->getMessage());
    pa_al_settings_redirect('error', 'Could not save the AlphaLedger authorization policy.');
}
