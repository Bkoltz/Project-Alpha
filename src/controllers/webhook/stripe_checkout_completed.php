<?php
// src/controllers/webhook/stripe_checkout_completed.php
// Handler for checkout.session.completed events

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/notifications.php';
require_once __DIR__ . '/../../utils/stripe_financial_events.php';

function handleCheckoutSessionCompleted($pdo, $session) {
    $metadata = $session['metadata'] ?? [];
    if (!empty($metadata['pa_project_invoice_id']) || !empty($metadata['project_invoice_id'])) {
        require_once __DIR__ . '/../../utils/project_invoice_billing.php';
        if (!project_invoice_record_stripe_payment($pdo, $session)) {
            throw new RuntimeException('Project invoice payment could not be allocated.');
        }
        return;
    }
    $invoiceId = $metadata['invoice_id'] ?? $metadata['pa_invoice_id'] ?? null;
    
    if (!$invoiceId) {
        @error_log('[StripeWebhook] No invoice_id in session metadata');
        return;
    }
    
    $invoiceId = (int)$invoiceId;
    $amountTotal = ($session['amount_total'] ?? 0) / 100; // Convert from cents
    $paymentIntentId = !empty($session['payment_intent']) ? (string)$session['payment_intent'] : null;
    $paymentAmount = isset($metadata['original_amount']) ? (float)$metadata['original_amount'] : $amountTotal;
    $surchargeAmount = isset($metadata['surcharge_amount']) ? (float)$metadata['surcharge_amount'] : max(0, $amountTotal - $paymentAmount);
    $paymentStatus = $session['payment_status'] ?? '';
    
    if ($paymentStatus !== 'paid') {
        @error_log('[StripeWebhook] Session not paid: ' . $paymentStatus);
        return;
    }
    
    try {
    // Serialize payment recording with manual payments and other Stripe events.
    $pdo->beginTransaction();
    $invCheck = $pdo->prepare('SELECT id,total,client_id FROM invoices WHERE id=? FOR UPDATE');
    $invCheck->execute([$invoiceId]);
    $invoice = $invCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        $pdo->rollBack();
        @error_log('[StripeWebhook] Invoice ' . $invoiceId . ' not found - skipping payment recording');
        return;
    }
    
    $clientId = (int)($invoice['client_id'] ?? 0);
    
    // Idempotency check - prevent duplicate processing
    $existsStmt = $pdo->prepare('SELECT id FROM payments WHERE stripe_session_id = ? OR (stripe_payment_intent_id IS NOT NULL AND stripe_payment_intent_id = ?)');
    $existsStmt->execute([$session['id'], $paymentIntentId]);
    if ($existsStmt->fetchColumn()) {
        $pdo->rollBack();
        @error_log('[StripeWebhook] Session ' . $session['id'] . ' already processed - skipping');
        return;
    }
    
        // Record the payment
        $stmt = $pdo->prepare('
            INSERT INTO payments (client_id, invoice_id, amount, surcharge_paid, payment_method, stripe_session_id, stripe_payment_intent_id, status, payment_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
        ');
        $stmt->execute([$clientId, $invoiceId, $paymentAmount, $surchargeAmount, 'stripe', $session['id'], $paymentIntentId, 'succeeded']);
        $paymentId = (int)$pdo->lastInsertId();
        stripe_link_pending_financial_events($pdo, $paymentId, $paymentIntentId);
        
        // Update invoice status
        $sum = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount-refunded_amount,0)), 0) AS paid FROM payments WHERE invoice_id = ? AND status = "succeeded"');
        $sum->execute([$invoiceId]);
        $paid = (float)$sum->fetchColumn();
        
        $total = (float)$invoice['total'];
        $status = ($paid >= $total) ? 'paid' : 'partial';
        
        $pdo->prepare('UPDATE invoices SET status=?,amount_paid=?,balance_due=GREATEST(total-?,0),stripe_session_id=NULL,stripe_checkout_expires_at=NULL WHERE id=?')
            ->execute([$status, $paid, $paid, $invoiceId]);
        
        // Revoke public links if fully paid
        if ($status === 'paid') {
            try {
                $redir = '/?page=public-redirect&type=invoice&reason=paid';
                $rv = $pdo->prepare('UPDATE public_links SET revoked = 1, redirect = ? WHERE document_type = "invoice" AND document_id = ? AND revoked = 0');
                $rv->execute([$redir, $invoiceId]);
            } catch (Throwable $e) { /* ignore */ }
            
            // Mark linked contract as completed if exists
            $co = $pdo->prepare('SELECT contract_id FROM invoices WHERE id = ?');
            $co->execute([$invoiceId]);
            $contractId = (int)$co->fetchColumn();
            if ($contractId > 0) {
                $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?')->execute(['completed', $contractId]);
            }
        }
        
        $pdo->commit();
        @error_log('[StripeWebhook] Payment recorded for invoice ' . $invoiceId . ': $' . $paymentAmount . ' - status: ' . $status);
        
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
        @error_log('[StripeWebhook] Failed to record payment: ' . $e->getMessage());
        throw $e;
    }
}
