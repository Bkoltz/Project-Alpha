<?php
// src/controllers/webhook/stripe_payment_succeeded.php
// Handler for payment_intent.succeeded events

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/notifications.php';
require_once __DIR__ . '/../../utils/stripe_financial_events.php';

function handlePaymentIntentSucceeded($pdo, $paymentIntent) {
    $metadata = $paymentIntent['metadata'] ?? [];
    if (!empty($metadata['pa_project_invoice_id']) || !empty($metadata['project_invoice_id'])) {
        require_once __DIR__ . '/../../utils/project_invoice_billing.php';
        if (!project_invoice_record_stripe_payment($pdo, $paymentIntent)) {
            throw new RuntimeException('Project invoice payment could not be allocated.');
        }
        return;
    }
    $invoiceId = $metadata['pa_invoice_id'] ?? $metadata['invoice_id'] ?? null;
    $piId = $paymentIntent['id'] ?? null;
    
    // If no metadata, try to find invoice from charges data
    if (!$invoiceId) {
        $charges = $paymentIntent['charges']['data'] ?? [];
        if (!empty($charges)) {
            $charge = $charges[0];
            $chargeMetadata = $charge['metadata'] ?? [];
            $invoiceId = $chargeMetadata['pa_invoice_id'] ?? $chargeMetadata['invoice_id'] ?? null;
            
            // Also check description for invoice reference
            if (!$invoiceId) {
                $description = $charge['description'] ?? '';
                if (preg_match('/Invoice\s+I-(\d+)/i', $description, $matches)) {
                    $invoiceId = $matches[1];
                }
            }
        }
    }
    
    if (!$invoiceId || !$piId) {
        @error_log('[StripeWebhook] PaymentIntent missing invoice_id or id. metadata=' . json_encode($metadata) . ' piId=' . $piId);
        return;
    }
    
    $invoiceId = (int)$invoiceId;
    $amountTotal = ($paymentIntent['amount'] ?? 0) / 100; // Convert from cents
    $paymentAmount = isset($metadata['original_amount']) ? (float)$metadata['original_amount'] : $amountTotal;
    $surchargeAmount = isset($metadata['surcharge_amount']) ? (float)$metadata['surcharge_amount'] : max(0, $amountTotal - $paymentAmount);
    
    try {
    // Serialize payment recording with manual payments and Checkout webhooks.
    $pdo->beginTransaction();
    $invCheck = $pdo->prepare('SELECT id,total,client_id FROM invoices WHERE id=? FOR UPDATE');
    $invCheck->execute([$invoiceId]);
    $invoice = $invCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        $pdo->rollBack();
        @error_log('[StripeWebhook] Invoice ' . $invoiceId . ' not found - skipping PaymentIntent recording');
        return;
    }
    
    $clientId = (int)($invoice['client_id'] ?? 0);
    
    // Check if already recorded (idempotency)
    $existsStmt = $pdo->prepare('SELECT id FROM payments WHERE stripe_payment_intent_id = ?');
    $existsStmt->execute([$piId]);
    if ($existsStmt->fetchColumn()) {
        $pdo->rollBack();
        @error_log('[StripeWebhook] PaymentIntent already recorded: ' . $piId);
        return;
    }
    
        // Record the payment
        // Legacy metadata is retained only as Stripe-side history. It never
        // grants collection authority or re-enables PA's retired AutoPay path.
        $isAutoPay = 0;
        $stmt = $pdo->prepare('
            INSERT INTO payments (client_id, invoice_id, amount, surcharge_paid, payment_method, stripe_payment_intent_id, auto_pay_attempt, status, payment_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
        ');
        $stmt->execute([$clientId, $invoiceId, $paymentAmount, $surchargeAmount, 'stripe', $piId, $isAutoPay, 'succeeded']);
        $paymentId = (int)$pdo->lastInsertId();
        stripe_link_pending_financial_events($pdo, $paymentId, $piId);
        
        // COMPLIANCE: If the card was debit/prepaid, refund the surcharge (Durbin Amendment)
        $surchargePaid = (float)$surchargeAmount;
        if ($surchargePaid > 0) {
            require_once __DIR__ . '/../../services/StripeProcessor.php';
            $processor = StripeProcessor::fromAppConfig($GLOBALS['appConfig'] ?? []);
            if ($processor && !$processor->isCreditCardPayment($paymentIntent)) {
                $chargeId = $paymentIntent['charges']['data'][0]['id'] ?? null;
                if ($chargeId && $processor->refundSurcharge($chargeId, $surchargePaid)) {
                    $pdo->prepare('UPDATE payments SET surcharge_refunded = 1, surcharge_refund_amount = ? WHERE stripe_payment_intent_id = ?')
                        ->execute([$surchargePaid, $piId]);
                    @error_log('[StripeWebhook] Refunded surcharge $' . $surchargePaid . ' for debit card on invoice ' . $invoiceId);
                } else {
                    @error_log('[StripeWebhook] WARNING: failed to refund surcharge for debit card on invoice ' . $invoiceId);
                }
            }
        }
        
        // Update invoice status
        $sum = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount-refunded_amount,0)), 0) AS paid FROM payments WHERE invoice_id = ? AND status = "succeeded"');
        $sum->execute([$invoiceId]);
        $paid = (float)$sum->fetchColumn();
        
        $total = (float)$invoice['total'];
        $status = ($paid >= $total) ? 'paid' : 'partial';
        
        $pdo->prepare('UPDATE invoices SET status=?,amount_paid=?,balance_due=GREATEST(total-?,0),stripe_session_id=NULL,stripe_checkout_expires_at=NULL WHERE id=?')
            ->execute([$status, $paid, $paid, $invoiceId]);
        
        // If paid, revoke public links and complete contract
        if ($status === 'paid') {
            try {
                $redir = '/?page=public-redirect&type=invoice&reason=paid';
                $rv = $pdo->prepare('UPDATE public_links SET revoked = 1, redirect = ? WHERE document_type = "invoice" AND document_id = ? AND revoked = 0');
                $rv->execute([$redir, $invoiceId]);
            } catch (Throwable $e) { /* ignore */ }
            
            $co = $pdo->prepare('SELECT contract_id FROM invoices WHERE id = ?');
            $co->execute([$invoiceId]);
            $contractId = (int)$co->fetchColumn();
            if ($contractId > 0) {
                $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?')->execute(['completed', $contractId]);
            }
        }
        
        $pdo->commit();
        @error_log('[StripeWebhook] PaymentIntent recorded for invoice ' . $invoiceId . ': $' . $paymentAmount . ' - status: ' . $status);
        
        // Notify admin
        try {
            notify_admin_invoice_paid($pdo, $GLOBALS['appConfig'] ?? [], $invoiceId, $paymentAmount, $status);
        } catch (Throwable $e) {
            @error_log('[StripeWebhook] Failed to send admin notification: ' . $e->getMessage());
        }
        try {
            require_once __DIR__ . '/../../utils/payment_receipts.php';
            payment_receipt_issue($pdo, $paymentId, $GLOBALS['appConfig'] ?? []);
        } catch (Throwable $e) {
            @error_log('[StripeWebhook] Receipt issue failed: ' . $e->getMessage());
        }
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @error_log('[StripeWebhook] Failed to record PaymentIntent: ' . $e->getMessage());
        throw $e;
    }
}
