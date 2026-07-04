<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/acl.php';
require_once __DIR__ . '/../utils/audit.php';
require_once __DIR__ . '/../utils/csrf.php';
require_once __DIR__ . '/../utils/invoice_lifecycle.php';

csrf_verify_post_or_redirect('payments-list');

$paymentId = (int)($_POST['payment_id'] ?? 0);
$amount = (float)($_POST['amount'] ?? 0);
if ($paymentId <= 0 || $amount <= 0) {
    header('Location: /?page=payments-list&error=' . urlencode('Invalid refund amount.'));
    exit;
}

if (!can_access_record($pdo, 'payments', $paymentId, (int)($_SESSION['user']['id'] ?? 0))) {
    header('Location: /?page=payments-list&error=' . urlencode('Permission denied'));
    exit;
}

$pdo->beginTransaction();
try {
    invoice_ensure_payments_schema($pdo);
    $stmt = $pdo->prepare('
        SELECT id, invoice_id, amount, refunded_amount, disputed_amount, status
        FROM payments
        WHERE id=?
        FOR UPDATE
    ');
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment || strtolower((string)$payment['status']) !== 'succeeded') {
        throw new RuntimeException('Only successful payments can be refunded.');
    }

    $paidAmount = (float)$payment['amount'];
    $refunded = (float)$payment['refunded_amount'];
    $disputed = (float)$payment['disputed_amount'];
    $remaining = max(0.0, $paidAmount - $refunded - $disputed);
    if ($amount > $remaining + 0.005) {
        throw new RuntimeException('Refund cannot exceed the remaining payment amount.');
    }

    $pdo->prepare('UPDATE payments SET refunded_amount = refunded_amount + ? WHERE id=?')
        ->execute([$amount, $paymentId]);

    $totals = null;
    if (!empty($payment['invoice_id'])) {
        $totals = invoice_refresh_payment_totals($pdo, (int)$payment['invoice_id']);
    }

    $pdo->commit();
    audit_log($pdo, 'payment.refund_recorded', 'payment', $paymentId, [
        'amount' => $amount,
        'invoice_id' => !empty($payment['invoice_id']) ? (int)$payment['invoice_id'] : null,
        'invoice_status' => $totals['status'] ?? null,
    ]);
    $GLOBALS['__audit_logged'] = true;
    header('Location: /?page=payments-list&refunded=1');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: /?page=payments-list&error=' . urlencode($e->getMessage()));
}
exit;
