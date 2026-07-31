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
require_once __DIR__ . '/../../utils/password_reset_tokens.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/acl.php';

use App\Utils\PasskeyException;
use App\Utils\PasskeyService;

passkey_require_post();
$data = passkey_request_json();
passkey_require_csrf('passkey_login', $data);
$challengeId = (string)($data['challenge_id'] ?? '');
$credential = is_array($data['credential'] ?? null) ? $data['credential'] : [];

try {
    $user = (new PasskeyService($pdo, $appConfig))->finishAuthentication($challengeId, $credential, get_client_ip());
    $userId = (int)$user['user_id'];
    $sessionUser = [
        'id' => $userId,
        'email' => (string)$user['email'],
        'role' => (string)$user['role'],
        'app_role' => (string)$user['role'],
        'auth_version' => (int)$user['auth_version'],
        'permissions_hash' => compute_permissions_hash($pdo, $userId, 0),
    ];

    session_regenerate_id(true);
    unset($_SESSION['2fa_pending']);
    $_SESSION['user'] = $sessionUser;
    App\Security\SessionPolicy::completeAuthentication('passkey');
    password_reset_revoke_for_user($pdo, $userId);
    audit_log($pdo, 'auth.passkey_login_success', 'user', $userId, ['ip' => get_client_ip()], $userId);

    $redirect = '/';
    if (!empty($user['force_password_reset'])) {
        $redirect = '/?page=account&force=1';
    } elseif (array_key_exists('tos_accepted_at', $user) && ($user['tos_accepted_at'] === null || $user['tos_accepted_at'] === false)) {
        $redirect = '/?page=legal/tos-accept';
    } elseif (!user_can($pdo, $userId, 'financial.view', 0)) {
        $redirect = '/?page=user-dashboard';
    }
    passkey_json(true, 'passkey_authenticated', 'Signed in.', 200, ['redirect' => $redirect]);
} catch (PasskeyException $e) {
    try { audit_log($pdo, 'auth.passkey_login_failed', null, null, ['code' => $e->errorCode, 'ip' => get_client_ip()]); } catch (Throwable $ignored) {}
    passkey_json(false, $e->errorCode, $e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    passkey_json(false, 'passkey_login_failed', 'Passkey sign-in failed. Use another sign-in method.', 500);
}
