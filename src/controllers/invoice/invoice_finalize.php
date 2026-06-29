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

try {
    $userId = (int)($_SESSION['user']['id'] ?? 0) ?: null;
    invoice_finalize($pdo, $id, $appConfig, 'manual_finalize', $userId);
    $sent = invoice_send_finalized($pdo, $id, $appConfig, 'manual_finalize_' . date('YmdHis'));
    audit_log($pdo, 'invoice.finalize', 'invoice', $id, ['sent' => $sent, 'user_id' => $userId]);
    $message = $sent ? '&finalized=1&emailed=1' : '&finalized=1&email_err=' . urlencode('Invoice finalized, but email was not sent.');
    header('Location: /?page=invoice/invoice-details&id=' . $id . $message);
} catch (Throwable $e) {
    header('Location: /?page=invoice/invoice-details&id=' . $id . '&error=' . urlencode($e->getMessage()));
}
exit;
