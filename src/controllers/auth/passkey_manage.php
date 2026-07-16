<?php

declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/client_ip.php';
require_once __DIR__ . '/../../utils/passkey_auth.php';
require_once __DIR__ . '/../../utils/audit.php';

use App\Utils\PasskeyException;
use App\Utils\PasskeyService;

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId < 1) { header('Location: /?page=login'); exit; }
$submitted = (string)($_POST['_token'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_sf_is_valid('passkey_manage', $submitted)) {
    header('Location: /?page=passkeys&error=' . urlencode('Invalid request. Refresh the page and try again.'));
    exit;
}

try {
    $service = new PasskeyService($pdo, $appConfig);
    $service->assertManagementAllowed($userId, get_client_ip());
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id=? AND is_disabled=0 AND deleted_at IS NULL');
    $stmt->execute([$userId]);
    $hash = $stmt->fetchColumn();
    if (!is_string($hash) || !password_verify((string)($_POST['current_password'] ?? ''), $hash)) {
        $service->recordManagementAttempt($userId, get_client_ip(), false, 'reauthentication_failed');
        audit_log($pdo, 'auth.passkey_management_denied', 'user', $userId, ['reason' => 'reauthentication_failed'], $userId);
        throw new PasskeyException('reauthentication_failed', 'The current password is incorrect.', 403);
    }
    $id = (int)($_POST['credential_id'] ?? 0);
    if (($_POST['action'] ?? '') === 'rename') {
        $service->rename($userId, $id, (string)($_POST['name'] ?? ''), get_client_ip());
        audit_log($pdo, 'auth.passkey_renamed', 'passkey', $id, [], $userId);
        header('Location: /?page=passkeys&success=' . urlencode('Passkey renamed.'));
        exit;
    }
    if (($_POST['action'] ?? '') === 'revoke') {
        $service->revoke($userId, $id, $userId, get_client_ip());
        audit_log($pdo, 'auth.passkey_revoked', 'passkey', $id, [], $userId);
        header('Location: /?page=passkeys&success=' . urlencode('Passkey removed.'));
        exit;
    }
    throw new PasskeyException('passkey_action_invalid', 'Unknown passkey action.', 422);
} catch (PasskeyException $e) {
    header('Location: /?page=passkeys&error=' . urlencode($e->getMessage()));
    exit;
} catch (Throwable $e) {
    header('Location: /?page=passkeys&error=' . urlencode('The passkey could not be updated.'));
    exit;
}
