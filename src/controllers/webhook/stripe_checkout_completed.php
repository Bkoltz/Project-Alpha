<?php
// src/controllers/webhook/stripe_checkout_completed.php
// Handler for checkout.session.completed events

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/notifications.php';

function handleCheckoutSessionCompleted($pdo, $session) {
    $metadata = $session['metadata'] ?? [];
    $invoiceId = $metadata['invoice_id'] ?? $metadata['pa_invoice_id'] ?? null;
    
    if (!$invoiceId) {
        @error_log('[StripeWebhook] No invoice_id in session metadata');
        return;
    }
    
    $invoiceId = (int)$invoiceId;
    $amountTotal = ($session['amount_total'] ?? 0) / 100; // Convert from cents
    $paymentStatus = $session['payment_status'] ?? '';
    
    if ($paymentStatus !== 'paid') {
        @error_log('[StripeWebhook] Session not paid: ' . $paymentStatus);
        return;
    }
    
    // Verify invoice exists and get client info before processing
    $invCheck = $pdo->prepare('SELECT id, total, client_id FROM invoices WHERE id = ?');
    $invCheck->execute([$invoiceId]);
    $invoice = $invCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        @error_log('[StripeWebhook] Invoice ' . $invoiceId . ' not found - skipping payment recording');
        return;
    }
    
    $clientId = (int)($invoice['client_id'] ?? 0);
    
    // Idempotency check - prevent duplicate processing
    $existsStmt = $pdo->prepare('SELECT id FROM payments WHERE stripe_session_id = ?');
    $existsStmt->execute([$session['id']]);
    if ($existsStmt->fetchColumn()) {
        @error_log('[StripeWebhook] Session ' . $session['id'] . ' already processed - skipping');
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Record the payment
        $stmt = $pdo->prepare('INSERT INTO payments (client_id, invoice_id, amount, payment_method, stripe_session_id, status, payment_date) VALUES (?, ?, ?, ?, ?, ?, CURDATE())');
        $stmt->execute([$clientId, $invoiceId, $amountTotal, 'stripe', $session['id'], 'succeeded']);
        
        // Update invoice status
        $sum = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS paid FROM payments WHERE invoice_id = ? AND status = "succeeded"');
        $sum->execute([$invoiceId]);
        $paid = (float)$sum->fetchColumn();
        
        $total = (float)$invoice['total'];
        $status = ($paid >= $total) ? 'paid' : 'partial';
        
        $pdo->prepare('UPDATE invoices SET status = ?, amount_paid = ? WHERE id = ?')->execute([$status, $paid, $invoiceId]);
        
        // Revoke public links if fully paid
        if ($status === 'paid') {
            try {
                $redir = '/?page=public-redirect&type=invoice&reason=paid';
                $rv = $pdo->prepare('UPDATE public_links SET revoked = 1, redirect = ? WHERE type = "invoice" AND record_id = ? AND revoked = 0');
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
        @error_log('[StripeWebhook] Payment recorded for invoice ' . $invoiceId . ': $' . $amountTotal . ' - status: ' . $status);
        
        // Notify admin
        try {
            notify_admin_invoice_paid($pdo, $invoiceId, $amountTotal, 'stripe');
        } catch (Throwable $e) {
            @error_log('[StripeWebhook] Failed to send admin notification: ' . $e->getMessage());
        }
        
    } catch (Throwable $e) {
        $pdo->rollBack();
        @error_log('[StripeWebhook] Failed to record payment: ' . $e->getMessage());
        throw $e;
    }
}
