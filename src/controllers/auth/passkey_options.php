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

use App\Utils\PasskeyException;
use App\Utils\PasskeyService;

passkey_require_post();
$data = passkey_request_json();
passkey_require_csrf('passkey_login', $data);

try {
    $result = (new PasskeyService($pdo, $appConfig))->authenticationOptions(get_client_ip());
    passkey_json(true, 'passkey_options_created', 'Passkey options created.', 200, $result);
} catch (PasskeyException $e) {
    passkey_json(false, $e->errorCode, $e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    passkey_json(false, 'passkey_unavailable', 'Passkey sign-in is temporarily unavailable.', 503);
}
