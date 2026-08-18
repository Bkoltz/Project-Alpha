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

$deliveryId = (string)($_POST['delivery_id'] ?? '');
try {
    (new \App\Services\ManagedDeliveryService())->requeueRevocation($pdo, $deliveryId);
    $result = (new \App\Services\ManagedDeliveryIntentSender())->deliverDeliveryId($pdo, $deliveryId, null, true);
    $status = $result['accepted'] === 1 ? 'accepted' : ($result['dead_lettered'] === 1 ? 'failed' : 'queued');
    audit_log($pdo, 'managed_delivery.revocation_requeued', 'managed_delivery_intent', $deliveryId, ['delivery_status' => $status]);
    header('Location: /?page=settings&tab=links&delivery=' . $status);
} catch (Throwable $error) {
    $diagnostic = substr(hash('sha256', get_class($error) . ':' . $error->getMessage()), 0, 12);
    error_log('[managed_delivery_retry] failed code=' . $diagnostic);
    header('Location: /?page=settings&tab=links&delivery=failed');
}
exit;
