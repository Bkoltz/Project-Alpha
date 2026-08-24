<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

header('Content-Type: application/json');
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}
if (!csrf_validate()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

try {
    $capabilities = (new \App\Services\ManagedDeliveryIntentSender())->preflight($pdo);
    echo json_encode([
        'success' => true,
        'status' => 'ready',
        'integrationEnabled' => $capabilities['integrationEnabled'],
        'portalSupported' => $capabilities['portalSupported'],
        'guestSupported' => $capabilities['guestSupported'],
        'revocationSupported' => $capabilities['revocationSupported'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $diagnostic = substr(hash('sha256', get_class($error) . ':' . $error->getMessage()), 0, 12);
    error_log('[managed_delivery_preflight] failed code=' . $diagnostic);
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Delivery capability test failed.', 'diagnostic' => $diagnostic]);
}
