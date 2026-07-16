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
    $result = (new PasskeyService($pdo, $appConfig))->finishRegistration(
        $userId,
        (string)($data['challenge_id'] ?? ''),
        is_array($data['credential'] ?? null) ? $data['credential'] : [],
        get_client_ip()
    );
    $_SESSION['authn'] = ['method' => 'passkey_registration', 'authenticated_at' => time()];
    audit_log($pdo, 'auth.passkey_registered', 'passkey', (int)$result['id'], ['name' => $result['name']], $userId);
    passkey_json(true, 'passkey_registered', 'Passkey added.', 201, $result);
} catch (PasskeyException $e) {
    try { audit_log($pdo, 'auth.passkey_registration_failed', 'user', $userId, ['code' => $e->errorCode], $userId); } catch (Throwable $ignored) {}
    passkey_json(false, $e->errorCode, $e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    passkey_json(false, 'passkey_registration_failed', 'The passkey could not be added.', 500);
}
