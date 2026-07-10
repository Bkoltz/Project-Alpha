<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/acl.php';
require_once __DIR__ . '/../utils/audit.php';
require_once __DIR__ . '/../utils/csrf.php';
require_once __DIR__ . '/../utils/payment_corrections.php';

csrf_verify_post_or_redirect('payments-list');

$paymentId = (int)($_POST['payment_id'] ?? 0);
$targetInvoiceId = (int)($_POST['target_invoice_id'] ?? 0);
$replacementPaymentId = (int)($_POST['replacement_payment_id'] ?? 0) ?: null;
$voidSource = !empty($_POST['void_source']);
$reason = trim((string)($_POST['reason'] ?? ''));
$userId = (int)($_SESSION['user']['id'] ?? 0) ?: null;

$errorUrl = '/?page=payments/payment-correction&payment_id=' . $paymentId;
if ($paymentId <= 0 || $targetInvoiceId <= 0) {
    header('Location: ' . $errorUrl . '&error=' . urlencode('Select a payment and target invoice.'));
    exit;
}

try {
    require_record_ownership($pdo, 'payments', $paymentId);
    require_record_ownership($pdo, 'invoices', $targetInvoiceId);
    if ($replacementPaymentId) {
        require_record_ownership($pdo, 'payments', $replacementPaymentId);
    }

    $result = payment_reallocate_to_invoice(
        $pdo,
        $paymentId,
        $targetInvoiceId,
        $replacementPaymentId,
        $voidSource,
        $appConfig,
        $reason,
        $userId
    );

    audit_log($pdo, 'payment.allocation_corrected', 'payment', $paymentId, [
        'correction_id' => $result['correction_id'],
        'source_invoice_id' => $result['source_invoice_id'],
        'target_invoice_id' => $result['target_invoice_id'],
        'reversed_payment_id' => $result['reversed_payment_id'],
        'source_voided' => $result['source_voided'],
        'reason' => $reason,
        'processor_action' => 'none',
    ], $userId);

    header(
        'Location: /?page=invoice/invoice-details&id=' . (int)$result['target_invoice_id']
        . '&payment_corrected=1&source_invoice_id=' . (int)$result['source_invoice_id']
    );
} catch (Throwable $e) {
    header('Location: ' . $errorUrl . '&error=' . urlencode($e->getMessage()));
}
exit;
