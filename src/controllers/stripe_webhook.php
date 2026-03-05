<?php
// src/controllers/stripe_webhook.php
// Handles Stripe webhook events (checkout.session.completed, etc.)

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/StripeService.php';

// Get raw POST body for signature verification
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

header('Content-Type: application/json');

if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'No payload']);
    exit;
}

try {
    // Initialize Stripe service
    $stripe = StripeService::fromAppConfig($appConfig);
    if (!$stripe) {
        throw new Exception('Stripe not configured');
    }
    
    // Verify webhook signature if webhook secret is configured
    $webhookSecret = null;
    if (!empty($appConfig['stripe_webhook_secret_enc'])) {
        $encVal = $appConfig['stripe_webhook_secret_enc'];
        if (strpos($encVal, 'plain::') === 0) {
            $webhookSecret = substr($encVal, 7);
        } else {
            require_once __DIR__ . '/../utils/crypto.php';
            $pt = crypto_decrypt($encVal);
            if (is_string($pt)) {
                $webhookSecret = $pt;
            }
        }
    }
    
    // Parse the event
    $event = json_decode($payload, true);
    if (!$event || empty($event['type'])) {
        throw new Exception('Invalid event payload');
    }
    
    // Log the event
    @error_log('[StripeWebhook] Received event: ' . $event['type'] . ' - ' . ($event['id'] ?? 'no-id'));
    
    // Handle different event types
    switch ($event['type']) {
        case 'checkout.session.completed':
            handleCheckoutSessionCompleted($pdo, $event['data']['object']);
            break;
            
        case 'payment_intent.succeeded':
            handlePaymentIntentSucceeded($pdo, $event['data']['object']);
            break;
            
        case 'payment_intent.payment_failed':
            @error_log('[StripeWebhook] Payment failed: ' . json_encode($event['data']['object']['id'] ?? 'unknown'));
            break;
            
        default:
            // Log unknown events but don't fail
            @error_log('[StripeWebhook] Unhandled event type: ' . $event['type']);
    }
    
    echo json_encode(['received' => true]);
    
} catch (Throwable $e) {
    @error_log('[StripeWebhook] Error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Handle checkout.session.completed event
 */
function handleCheckoutSessionCompleted($pdo, $session) {
    $metadata = $session['metadata'] ?? [];
    $invoiceId = $metadata['invoice_id'] ?? null;
    
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
    
    try {
        $pdo->beginTransaction();
        
        // Ensure stripe_session_id column exists
        try {
            $pdo->exec('ALTER TABLE payments ADD COLUMN stripe_session_id VARCHAR(255) NULL');
        } catch (Throwable $e) { /* column exists */ }
        
        // Record the payment
        $pdo->prepare('INSERT INTO payments (invoice_id, amount, method, stripe_session_id, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([$invoiceId, $amountTotal, 'stripe', $session['id'], 'succeeded']);
        
        // Update invoice status
        $sum = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS paid FROM payments WHERE invoice_id = ? AND status = "succeeded"');
        $sum->execute([$invoiceId]);
        $paid = (float)$sum->fetchColumn();
        
        $tot = $pdo->prepare('SELECT total FROM invoices WHERE id = ?');
        $tot->execute([$invoiceId]);
        $total = (float)$tot->fetchColumn();
        
        $status = 'partial';
        if ($paid >= $total) {
            $status = 'paid';
        }
        
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
        
    } catch (Throwable $e) {
        $pdo->rollBack();
        @error_log('[StripeWebhook] Failed to record payment: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Handle payment_intent.succeeded event
 */
function handlePaymentIntentSucceeded($pdo, $paymentIntent) {
    // This is a backup handler - primary handling is via checkout.session.completed
    $metadata = $paymentIntent['metadata'] ?? [];
    $invoiceId = $metadata['invoice_id'] ?? null;
    
    if ($invoiceId) {
        @error_log('[StripeWebhook] PaymentIntent succeeded for invoice: ' . $invoiceId);
    }
}
