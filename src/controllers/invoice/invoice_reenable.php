<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../../utils/audit.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=invoice/invoices-list&error=' . urlencode('Invalid invoice.'));
    exit;
}
require_record_ownership($pdo, 'invoices', $id);

try {
    $result = invoice_reenable_void($pdo, $id);
    audit_log($pdo, 'invoice.reenable', 'invoice', $id, [
        'restored_status' => $result['restored_status'],
        'previous_void_reason' => $result['reason'],
        'user_id' => (int)($_SESSION['user']['id'] ?? 0),
    ]);
    header('Location: /?page=invoice/invoice-details&id=' . $id . '&reenabled=1');
} catch (Throwable $e) {
    header('Location: /?page=invoice/invoice-details&id=' . $id . '&error=' . urlencode($e->getMessage()));
}
exit;
