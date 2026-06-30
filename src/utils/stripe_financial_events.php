<?php

function stripe_refresh_invoice_from_payments(PDO $pdo, int $invoiceId): void
{
    if ($invoiceId <= 0) {
        return;
    }
    $paidStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(GREATEST(amount-refunded_amount,0)),0)
         FROM payments WHERE invoice_id=? AND status="succeeded"'
    );
    $paidStmt->execute([$invoiceId]);
    $paid = (float)$paidStmt->fetchColumn();
    $invoice = $pdo->prepare('SELECT total,status FROM invoices WHERE id=?');
    $invoice->execute([$invoiceId]);
    $row = $invoice->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$row || in_array((string)($row['status'] ?? ''), ['void','cancelled','draft'], true)) {
        return;
    }
    $status = $paid <= 0 ? 'unpaid' : ($paid + 0.005 >= (float)$row['total'] ? 'paid' : 'partial');
    $pdo->prepare('UPDATE invoices SET amount_paid=?,balance_due=GREATEST(total-?,0),status=? WHERE id=?')
        ->execute([$paid, $paid, $status, $invoiceId]);
}

function stripe_refresh_payment_totals(PDO $pdo, ?int $paymentId): void
{
    if (!$paymentId) {
        return;
    }

    $pdo->prepare(
        'UPDATE payments p SET
           p.refunded_amount=COALESCE((SELECT SUM(r.amount) FROM stripe_refunds r WHERE r.payment_id=p.id AND r.status="succeeded"),0),
           p.disputed_amount=COALESCE((SELECT SUM(d.amount) FROM stripe_disputes d WHERE d.payment_id=p.id AND d.status IN ("needs_response","under_review","lost","funds_withdrawn")),0)
         WHERE p.id=?'
    )->execute([$paymentId]);

    $invoiceStmt = $pdo->prepare('SELECT invoice_id FROM payments WHERE id=?');
    $invoiceStmt->execute([$paymentId]);
    $invoiceId = (int)($invoiceStmt->fetchColumn() ?: 0);
    if ($invoiceId <= 0) {
        return;
    }

    stripe_refresh_invoice_from_payments($pdo, $invoiceId);
}

function stripe_refresh_project_payment_totals(PDO $pdo, ?int $projectPaymentId): void
{
    if (!$projectPaymentId) {
        return;
    }
    $pdo->prepare(
        'UPDATE project_invoice_payments pp SET
           pp.refunded_amount=COALESCE((SELECT SUM(r.amount) FROM stripe_refunds r WHERE r.project_invoice_payment_id=pp.id AND r.status="succeeded"),0),
           pp.disputed_amount=COALESCE((SELECT SUM(d.amount) FROM stripe_disputes d WHERE d.project_invoice_payment_id=pp.id AND d.status IN ("needs_response","under_review","lost","funds_withdrawn")),0)
         WHERE pp.id=?'
    )->execute([$projectPaymentId]);
    $parent = $pdo->prepare('SELECT project_invoice_id,refunded_amount,disputed_amount FROM project_invoice_payments WHERE id=?');
    $parent->execute([$projectPaymentId]);
    $projectPayment = $parent->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$projectPayment) {
        return;
    }

    $refundRemaining = (float)$projectPayment['refunded_amount'];
    $disputeRemaining = (float)$projectPayment['disputed_amount'];
    $children = $pdo->prepare('SELECT id,invoice_id,amount FROM payments WHERE project_invoice_payment_id=? ORDER BY id DESC');
    $children->execute([$projectPaymentId]);
    foreach ($children->fetchAll(PDO::FETCH_ASSOC) as $child) {
        $amount = (float)$child['amount'];
        $refunded = min($amount, max(0, $refundRemaining));
        $disputed = min(max(0, $amount - $refunded), max(0, $disputeRemaining));
        $pdo->prepare('UPDATE payments SET refunded_amount=?,disputed_amount=? WHERE id=?')
            ->execute([$refunded, $disputed, (int)$child['id']]);
        $refundRemaining -= $refunded;
        $disputeRemaining -= $disputed;
        stripe_refresh_invoice_from_payments($pdo, (int)$child['invoice_id']);
    }
    require_once __DIR__ . '/project_invoice_billing.php';
    project_invoice_refresh_status($pdo, (int)$projectPayment['project_invoice_id']);
}

function stripe_link_pending_financial_events(PDO $pdo, int $paymentId, ?string $paymentIntentId): void
{
    if ($paymentId <= 0 || !$paymentIntentId) {
        return;
    }
    $pdo->prepare('UPDATE stripe_refunds SET payment_id=? WHERE stripe_payment_intent_id=? AND payment_id IS NULL')
        ->execute([$paymentId, $paymentIntentId]);
    $pdo->prepare('UPDATE stripe_disputes SET payment_id=? WHERE stripe_payment_intent_id=? AND payment_id IS NULL')
        ->execute([$paymentId, $paymentIntentId]);
    stripe_refresh_payment_totals($pdo, $paymentId);
}

function stripe_link_pending_project_financial_events(PDO $pdo, int $projectPaymentId, ?string $paymentIntentId): void
{
    if ($projectPaymentId <= 0 || !$paymentIntentId) {
        return;
    }
    $pdo->prepare('UPDATE stripe_refunds SET project_invoice_payment_id=? WHERE stripe_payment_intent_id=? AND payment_id IS NULL AND project_invoice_payment_id IS NULL')
        ->execute([$projectPaymentId, $paymentIntentId]);
    $pdo->prepare('UPDATE stripe_disputes SET project_invoice_payment_id=? WHERE stripe_payment_intent_id=? AND payment_id IS NULL AND project_invoice_payment_id IS NULL')
        ->execute([$projectPaymentId, $paymentIntentId]);
    stripe_refresh_project_payment_totals($pdo, $projectPaymentId);
}

function stripe_record_refund(PDO $pdo, array $refund): void
{
    if (str_starts_with((string)($refund['id'] ?? ''), 'ch_')) {
        foreach (($refund['refunds']['data'] ?? []) as $item) {
            if (is_array($item)) {
                stripe_record_refund($pdo, $item);
            }
        }
        return;
    }

    $refundId = (string)($refund['id'] ?? '');
    if ($refundId === '') {
        return;
    }
    $paymentIntentId = is_string($refund['payment_intent'] ?? null) ? $refund['payment_intent'] : null;
    $paymentId = null;
    $projectPaymentId = null;
    if ($paymentIntentId) {
        $payment = $pdo->prepare('SELECT id FROM payments WHERE stripe_payment_intent_id=? LIMIT 1');
        $payment->execute([$paymentIntentId]);
        $paymentId = (int)($payment->fetchColumn() ?: 0) ?: null;
        if (!$paymentId) {
            $projectPayment = $pdo->prepare('SELECT id FROM project_invoice_payments WHERE stripe_payment_intent_id=? LIMIT 1');
            $projectPayment->execute([$paymentIntentId]);
            $projectPaymentId = (int)($projectPayment->fetchColumn() ?: 0) ?: null;
        }
    }
    $pdo->prepare(
        'INSERT INTO stripe_refunds (stripe_refund_id,stripe_payment_intent_id,payment_id,project_invoice_payment_id,amount,status,reason)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE payment_id=VALUES(payment_id),project_invoice_payment_id=VALUES(project_invoice_payment_id),amount=VALUES(amount),status=VALUES(status),reason=VALUES(reason)'
    )->execute([
        $refundId,
        $paymentIntentId,
        $paymentId,
        $projectPaymentId,
        ((float)($refund['amount'] ?? 0)) / 100,
        (string)($refund['status'] ?? 'unknown'),
        isset($refund['reason']) ? (string)$refund['reason'] : null,
    ]);
    stripe_refresh_payment_totals($pdo, $paymentId);
    stripe_refresh_project_payment_totals($pdo, $projectPaymentId);
}

function stripe_record_dispute(PDO $pdo, array $dispute, ?string $eventType = null): void
{
    $disputeId = (string)($dispute['id'] ?? '');
    if ($disputeId === '') {
        return;
    }
    $paymentIntentId = is_string($dispute['payment_intent'] ?? null) ? $dispute['payment_intent'] : null;
    $paymentId = null;
    $projectPaymentId = null;
    if ($paymentIntentId) {
        $payment = $pdo->prepare('SELECT id FROM payments WHERE stripe_payment_intent_id=? LIMIT 1');
        $payment->execute([$paymentIntentId]);
        $paymentId = (int)($payment->fetchColumn() ?: 0) ?: null;
        if (!$paymentId) {
            $projectPayment = $pdo->prepare('SELECT id FROM project_invoice_payments WHERE stripe_payment_intent_id=? LIMIT 1');
            $projectPayment->execute([$paymentIntentId]);
            $projectPaymentId = (int)($projectPayment->fetchColumn() ?: 0) ?: null;
        }
    }
    $evidenceDue = !empty($dispute['evidence_details']['due_by'])
        ? date('Y-m-d H:i:s', (int)$dispute['evidence_details']['due_by'])
        : null;
    $storedStatus = (string)($dispute['status'] ?? 'unknown');
    if ($eventType === 'charge.dispute.funds_withdrawn') {
        $storedStatus = 'funds_withdrawn';
    } elseif ($eventType === 'charge.dispute.funds_reinstated') {
        $storedStatus = 'funds_reinstated';
    }
    $pdo->prepare(
        'INSERT INTO stripe_disputes (stripe_dispute_id,stripe_payment_intent_id,payment_id,project_invoice_payment_id,amount,status,reason,evidence_due_at)
         VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE payment_id=VALUES(payment_id),project_invoice_payment_id=VALUES(project_invoice_payment_id),amount=VALUES(amount),status=VALUES(status),reason=VALUES(reason),evidence_due_at=VALUES(evidence_due_at)'
    )->execute([
        $disputeId,
        $paymentIntentId,
        $paymentId,
        $projectPaymentId,
        ((float)($dispute['amount'] ?? 0)) / 100,
        $storedStatus,
        isset($dispute['reason']) ? (string)$dispute['reason'] : null,
        $evidenceDue,
    ]);
    stripe_refresh_payment_totals($pdo, $paymentId);
    stripe_refresh_project_payment_totals($pdo, $projectPaymentId);
}
