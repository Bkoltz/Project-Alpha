<?php

declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/client_ip.php';
require_once __DIR__ . '/../../utils/passkey_auth.php';
require_once __DIR__ . '/../../utils/passkey_http.php';
require_once __DIR__ . '/../../utils/audit.php';

use App\Utils\PasskeyException;
use App\Utils\PasskeyService;

passkey_require_post();
$data = passkey_request_json();
passkey_require_csrf('passkey_manage', $data);
$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId < 1) { passkey_json(false, 'authentication_required', 'Sign in to manage passkeys.', 401); }

try {
    $service = new PasskeyService($pdo, $appConfig);
    $service->assertManagementAllowed($userId, get_client_ip());
    $stmt = $pdo->prepare('SELECT email,username,password_hash,is_disabled,deleted_at FROM users WHERE id=?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !empty($user['is_disabled']) || !empty($user['deleted_at']) || !password_verify((string)($data['current_password'] ?? ''), (string)$user['password_hash'])) {
        $service->recordManagementAttempt($userId, get_client_ip(), false, 'reauthentication_failed');
        audit_log($pdo, 'auth.passkey_registration_denied', 'user', $userId, ['reason' => 'reauthentication_failed'], $userId);
        passkey_json(false, 'reauthentication_failed', 'The current password is incorrect.', 403);
    }
    $display = trim((string)($user['username'] ?? '')) ?: (string)$user['email'];
    $result = $service->registrationOptions(
        $userId,
        (string)$user['email'],
        $display,
        (string)($data['name'] ?? ''),
        get_client_ip()
    );
    $service->recordManagementAttempt($userId, get_client_ip(), true);
    passkey_json(true, 'passkey_registration_options_created', 'Registration options created.', 200, $result);
} catch (PasskeyException $e) {
    passkey_json(false, $e->errorCode, $e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    passkey_json(false, 'passkey_registration_failed', 'Passkey registration is temporarily unavailable.', 503);
}
