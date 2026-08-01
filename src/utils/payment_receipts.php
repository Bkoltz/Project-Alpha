<?php

require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/invoice_lifecycle.php';

/**
 * Create and email one durable Project Alpha receipt per payment.
 */
function payment_receipt_issue(PDO $pdo, int $paymentId, array $appConfig, bool $sendEmail = true): ?array
{
    if (array_key_exists('payment_receipts_enabled', $appConfig) && empty($appConfig['payment_receipts_enabled'])) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT p.id,p.invoice_id,p.job_id,p.processor_transaction_id,p.amount,p.payment_date,p.payment_method,p.reference_number,
                i.doc_number,i.invoice_type,i.recipient_presentation_mode,j.job_code,
                COALESCE(c.name,ppt.payer_name) AS client_name,COALESCE(c.email,ppt.payer_email) AS email
         FROM payments p
         LEFT JOIN invoices i ON i.id=p.invoice_id
         LEFT JOIN jobs j ON j.id=p.job_id
         LEFT JOIN clients c ON c.id=p.client_id
         LEFT JOIN processor_payment_transactions ppt ON ppt.payment_id=p.id
         WHERE p.id=? AND p.status="succeeded"'
    );
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        return null;
    }
    if (empty($payment['invoice_id']) && !empty($payment['processor_transaction_id'])) {
        return null;
    }

    // The invoice's paid public link is the only external receipt for a
    // general-recipient invoice. Creating this separate receipt would copy the
    // private accounting client's identity and could email it automatically.
    if (pa_invoice_is_general_recipient($payment)) {
        return null;
    }

    $existing = $pdo->prepare('SELECT * FROM payment_receipts WHERE payment_id=?');
    $existing->execute([$paymentId]);
    $receipt = $existing->fetch(PDO::FETCH_ASSOC);

    if (!$receipt) {
        $token = bin2hex(random_bytes(32));
        $number = 'R-' . str_pad((string)$paymentId, 6, '0', STR_PAD_LEFT);
        $pdo->prepare(
            'INSERT INTO payment_receipts (payment_id,invoice_id,receipt_number,public_token,amount,email_to)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $paymentId,
            $payment['invoice_id'] ?: null,
            $number,
            $token,
            (float)$payment['amount'],
            filter_var((string)$payment['email'], FILTER_VALIDATE_EMAIL) ? $payment['email'] : null,
        ]);
        $existing->execute([$paymentId]);
        $receipt = $existing->fetch(PDO::FETCH_ASSOC);
    }

    if (!$sendEmail || !$receipt || !empty($receipt['emailed_at']) || empty($receipt['email_to'])) {
        return $receipt ?: null;
    }

    $url = '';
    if (!empty($appConfig['public_links_in_email'])) {
        $url = invoice_public_base_url($appConfig) . '/?page=payment-receipt&token=' . rawurlencode((string)$receipt['public_token']);
    }
    $invoiceLabel = !empty($payment['invoice_id']) ? ' for invoice ' . pa_invoice_label_from_row($payment) : '';
    $serviceLabel = empty($payment['invoice_id']) && !empty($payment['job_code'])
        ? ' for service job ' . (string)$payment['job_code']
        : '';
    $subject = 'Payment receipt ' . $receipt['receipt_number'];
    $body = '<p>Hello ' . htmlspecialchars((string)($payment['client_name'] ?: 'there')) . ',</p>'
        . '<p>We received your payment of <strong>$' . number_format((float)$payment['amount'], 2) . '</strong>'
        . htmlspecialchars($invoiceLabel . $serviceLabel) . '.</p>'
        . '<p>Receipt number: <strong>' . htmlspecialchars((string)$receipt['receipt_number']) . '</strong></p>'
        . ($url !== '' ? '<p><a href="' . htmlspecialchars($url) . '">View and print your Project Alpha receipt</a></p>' : '');

    [$ok, $error] = EmailService::sendEmail((string)$receipt['email_to'], $subject, $body);
    if ($ok) {
        $pdo->prepare('UPDATE payment_receipts SET emailed_at=NOW() WHERE id=?')->execute([(int)$receipt['id']]);
        $receipt['emailed_at'] = date('Y-m-d H:i:s');
    } else {
        @error_log('[payment_receipts] Receipt email failed for payment ' . $paymentId . ': ' . $error);
    }

    return $receipt;
}
