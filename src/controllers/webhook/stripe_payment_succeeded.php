<?php
// src/controllers/webhook/stripe_payment_succeeded.php
// Handler for payment_intent.succeeded events

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/notifications.php';

function handlePaymentIntentSucceeded($pdo, $paymentIntent) {
    $metadata = $paymentIntent['metadata'] ?? [];
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
    
    // Verify invoice exists and get client info before processing
    $invCheck = $pdo->prepare('SELECT id, total, client_id FROM invoices WHERE id = ?');
    $invCheck->execute([$invoiceId]);
    $invoice = $invCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        @error_log('[StripeWebhook] Invoice ' . $invoiceId . ' not found - skipping PaymentIntent recording');
        return;
    }
    
    $clientId = (int)($invoice['client_id'] ?? 0);
    
    // Check if already recorded (idempotency)
    $existsStmt = $pdo->prepare('SELECT id FROM payments WHERE stripe_payment_intent_id = ?');
    $existsStmt->execute([$piId]);
    if ($existsStmt->fetchColumn()) {
        @error_log('[StripeWebhook] PaymentIntent already recorded: ' . $piId);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Record the payment
        $isAutoPay = !empty($metadata['auto_pay']) ? 1 : 0;
        $stmt = $pdo->prepare('INSERT INTO payments (client_id, invoice_id, amount, payment_method, stripe_payment_intent_id, auto_pay_attempt, status, payment_date) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())');
        $stmt->execute([$clientId, $invoiceId, $amountTotal, 'stripe', $piId, $isAutoPay, 'succeeded']);
        
        // Update invoice status
        $sum = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS paid FROM payments WHERE invoice_id = ? AND status = "succeeded"');
        $sum->execute([$invoiceId]);
        $paid = (float)$sum->fetchColumn();
        
        $total = (float)$invoice['total'];
        $status = ($paid >= $total) ? 'paid' : 'partial';
        
        $pdo->prepare('UPDATE invoices SET status = ?, amount_paid = ? WHERE id = ?')->execute([$status, $paid, $invoiceId]);
        
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
        @error_log('[StripeWebhook] PaymentIntent recorded for invoice ' . $invoiceId . ': $' . $amountTotal . ' - status: ' . $status);
        
        // Notify admin
        try {
            notify_admin_invoice_paid($pdo, $invoiceId, $amountTotal, 'stripe');
        } catch (Throwable $e) {
            @error_log('[StripeWebhook] Failed to send admin notification: ' . $e->getMessage());
        }
        
    } catch (Throwable $e) {
        $pdo->rollBack();
        @error_log('[StripeWebhook] Failed to record PaymentIntent: ' . $e->getMessage());
        throw $e;
    }
}
