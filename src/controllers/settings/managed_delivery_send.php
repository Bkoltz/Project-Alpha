<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';

if (empty($_SESSION['user'])) {
    http_response_code(401);
    exit('Authentication required');
}
if (!csrf_validate()) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$parseReference = static function (string $value): array {
    $parts = explode(':', $value, 2);
    return count($parts) === 2 ? ['type' => $parts[0], 'public_id' => $parts[1]] : ['type' => '', 'public_id' => ''];
};

try {
    $scope = $parseReference((string)($_POST['scope_ref'] ?? ''));
    $audience = $parseReference((string)($_POST['audience_ref'] ?? ''));
    $delivery = (new \App\Services\ManagedDeliveryService())->queue($pdo, [
        'delivery_id' => (string)($_POST['delivery_id'] ?? ''),
        'scope_type' => $scope['type'],
        'scope_public_id' => $scope['public_id'],
        'audience_type' => $audience['type'],
        'audience_public_id' => $audience['public_id'],
        'access_mode' => (string)($_POST['access_mode'] ?? 'portal'),
        'expires_at' => (string)($_POST['expires_at'] ?? ''),
        'label' => (string)($_POST['label'] ?? ''),
    ], (int)$_SESSION['user']['id']);
    $status = $delivery['status'];
    if ($status === 'queued') {
        $result = (new \App\Services\ManagedDeliveryIntentSender())->deliverDeliveryId($pdo, $delivery['deliveryId']);
        $status = $result['accepted'] === 1 ? 'accepted' : 'queued';
    }
    if (!in_array($status, ['accepted', 'queued'], true)) $status = 'failed';
    audit_log($pdo, 'managed_delivery.intent_queued', 'managed_delivery_intent', $delivery['deliveryId'], [
        'access_mode' => (string)($_POST['access_mode'] ?? 'portal'),
        'delivery_status' => $status,
        'replayed' => $delivery['replayed'],
    ]);
    header('Location: /?page=settings&tab=links&delivery=' . $status);
} catch (Throwable $error) {
    $diagnostic = substr(hash('sha256', get_class($error) . ':' . $error->getMessage()), 0, 12);
    error_log('[managed_delivery_send] failed code=' . $diagnostic);
    header('Location: /?page=settings&tab=links&delivery=failed');
}
exit;
