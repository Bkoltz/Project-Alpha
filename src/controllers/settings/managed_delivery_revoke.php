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

try {
    $queued = (new \App\Services\ManagedDeliveryService())->queueRevocation(
        $pdo,
        (string)($_POST['target_delivery_id'] ?? ''),
        (string)($_POST['delivery_id'] ?? ''),
        (int)$_SESSION['user']['id']
    );
    $status = $queued['status'];
    if ($status === 'queued') {
        $result = (new \App\Services\ManagedDeliveryIntentSender())->deliverDeliveryId($pdo, $queued['deliveryId'], null, true);
        $status = $result['accepted'] === 1 ? 'accepted' : 'queued';
    }
    if (!in_array($status, ['accepted', 'queued'], true)) $status = 'failed';
    audit_log($pdo, 'managed_delivery.revocation_queued', 'managed_delivery_intent', $queued['deliveryId'], ['delivery_status' => $status]);
    header('Location: /?page=settings&tab=links&delivery=' . $status);
} catch (Throwable $error) {
    $diagnostic = substr(hash('sha256', get_class($error) . ':' . $error->getMessage()), 0, 12);
    error_log('[managed_delivery_revoke] failed code=' . $diagnostic);
    header('Location: /?page=settings&tab=links&delivery=failed');
}
exit;
