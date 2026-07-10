<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../../utils/audit.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=invoice/invoices-list&error=' . urlencode('Invalid invoice.'));
    exit;
}
require_record_ownership($pdo, 'invoices', $id);

$reason = trim((string)($_POST['reason'] ?? ''));
$userId = (int)($_SESSION['user']['id'] ?? 0) ?: null;

try {
    $result = invoice_void($pdo, $id, $appConfig, $reason, $userId);
    audit_log($pdo, 'invoice.void', 'invoice', $id, [
        'reason' => $result['reason'],
        'previous_status' => $result['previous_status'],
        'user_id' => $userId,
    ]);
    header('Location: /?page=invoice/invoice-details&id=' . $id . '&voided=1');
} catch (Throwable $e) {
    header('Location: /?page=invoice/invoice-details&id=' . $id . '&error=' . urlencode($e->getMessage()));
}
exit;
