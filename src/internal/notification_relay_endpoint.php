<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/NotificationRelayPolicy.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// This file is intentionally outside the public document root and is not routed by
// public/index.php. Operators may map it only on a separately secured private listener.
if (!NotificationRelayPolicy::isEnabled()) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

define('PA_STATELESS_API_NO_SESSION', true);
require_once __DIR__ . '/../utils/api_auth.php';

// Sensitive write access is explicit-only: legacy "full" keys do not inherit it.
$apiKey = api_require_key(['notifications.enqueue'], false);
require __DIR__ . '/../controllers/api/notification_relay_enqueue.php';
