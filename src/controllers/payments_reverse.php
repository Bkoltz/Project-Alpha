<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/acl.php';
require_once __DIR__ . '/../utils/audit.php';
require_once __DIR__ . '/../utils/csrf.php';
require_once __DIR__ . '/../utils/payment_corrections.php';

csrf_verify_post_or_redirect('payments-list');

$paymentId = (int)($_POST['payment_id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));
$userId = (int)($_SESSION['user']['id'] ?? 0) ?: null;

try {
    require_record_ownership($pdo, 'payments', $paymentId);
    $result = payment_reverse_manual_entry($pdo, $paymentId, $reason, $userId);
    audit_log($pdo, 'payment.manual_entry_reversed', 'payment', $paymentId, [
        'invoice_id' => $result['invoice_id'],
        'invoice_status' => $result['invoice_status'],
        'preserved_local_refund_amount' => $result['refunded_amount'],
        'reason' => $reason,
        'processor_action' => 'none',
    ], $userId);
    header('Location: /?page=payments-list&manual_reversed=1');
} catch (Throwable $e) {
    header('Location: /?page=payments-list&error=' . urlencode($e->getMessage()));
}
exit;
