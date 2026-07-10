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
$replacementPaymentIds = array_values(array_unique(array_filter(array_map(
    'intval',
    is_array($_POST['replacement_payment_ids'] ?? null)
        ? $_POST['replacement_payment_ids']
        : [$_POST['replacement_payment_id'] ?? 0]
))));
$clearLocalRefund = !empty($_POST['clear_local_refund']);
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
    foreach ($replacementPaymentIds as $replacementPaymentId) {
        require_record_ownership($pdo, 'payments', (int)$replacementPaymentId);
    }

    $processorRefundVerifiedAmount = null;
    if ($clearLocalRefund) {
        $paymentStmt = $pdo->prepare('
            SELECT payment_method,processor_provider,processor_payment_id,
                   stripe_payment_intent_id,stripe_session_id,refunded_amount
            FROM payments WHERE id=?
        ');
        $paymentStmt->execute([$paymentId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$payment) {
            throw new RuntimeException('Payment not found.');
        }
        $processorRefundVerifiedAmount = payment_verify_stripe_refunded_amount($payment, $appConfig);
    }

    $result = payment_reallocate_to_invoice(
        $pdo,
        $paymentId,
        $targetInvoiceId,
        $replacementPaymentIds,
        $clearLocalRefund,
        $processorRefundVerifiedAmount,
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
        'reversed_payment_ids' => $result['reversed_payment_ids'],
        'cleared_local_refund_amount' => $result['cleared_local_refund_amount'],
        'processor_refund_verified_amount' => $processorRefundVerifiedAmount,
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
