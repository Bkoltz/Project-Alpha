<?php
// src/controllers/webhook/stripe_payment_failed.php
// Handler for payment_intent.payment_failed events

function handlePaymentIntentFailed($pdo, $paymentIntent) {
    $metadata = $paymentIntent['metadata'] ?? [];
    $invoiceId = $metadata['pa_invoice_id'] ?? $metadata['invoice_id'] ?? null;
    $piId = $paymentIntent['id'] ?? null;
    
    if (!$invoiceId || !$piId) {
        @error_log('[StripeWebhook] Payment failed - missing metadata: ' . ($piId ?? 'no-id'));
        return;
    }
    
    $invoiceId = (int)$invoiceId;
    $errorMessage = $paymentIntent['last_payment_error']['message'] ?? 'Unknown error';
    
    // Log the failed payment
    @error_log('[StripeWebhook] Payment failed for invoice ' . $invoiceId . ': ' . $errorMessage);
    
    // Record failed payment attempt (optional - for tracking)
    try {
        $stmt = $pdo->prepare('INSERT INTO payments (invoice_id, amount, payment_method, stripe_payment_intent_id, auto_pay_attempt, status, payment_date) VALUES (?, ?, ?, ?, ?, ?, CURDATE())');
        $stmt->execute([
            $invoiceId,
            0, // Amount is 0 for failed payments
            'stripe',
            $piId,
            !empty($metadata['auto_pay']) ? 1 : 0,
            'failed'
        ]);
    } catch (Throwable $e) {
        @error_log('[StripeWebhook] Failed to record failed payment: ' . $e->getMessage());
    }
    
    // Future: Send notification to admin about failed payment
    // Future: Implement retry logic for auto-pay
    // Future: Update subscription status if applicable
}
