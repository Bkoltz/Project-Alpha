<?php
// src/controllers/webhook/stripe_webhooks.php
// Future-proof webhook router for Stripe events
// Routes events to appropriate handlers based on event type

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/StripeService.php';

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
            require_once __DIR__ . '/../../utils/crypto.php';
            $pt = crypto_decrypt($encVal);
            if (is_string($pt)) {
                $webhookSecret = $pt;
            }
        }
    }
    
    // Verify webhook signature if secret is available
    if ($webhookSecret && $sigHeader) {
        // Parse Stripe signature header: t=timestamp,v1=signature
        $sigParts = [];
        foreach (explode(',', $sigHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) $sigParts[$kv[0]] = $kv[1];
        }
        $timestamp = $sigParts['t'] ?? '';
        $sig = $sigParts['v1'] ?? '';
        
        if (!$timestamp || !$sig) {
            throw new Exception('Invalid webhook signature format');
        }
        
        // Reject if timestamp is older than 5 minutes (replay protection)
        if (abs(time() - (int)$timestamp) > 300) {
            throw new Exception('Webhook timestamp too old');
        }
        
        $signedPayload = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signedPayload, $webhookSecret);
        if (!hash_equals($expected, $sig)) {
            throw new Exception('Webhook signature verification failed');
        }
    } elseif ($webhookSecret) {
        // Secret configured but no signature header = reject
        throw new Exception('Missing webhook signature');
    }
    
    // Parse the event
    $event = json_decode($payload, true);
    if (!$event || empty($event['type'])) {
        throw new Exception('Invalid event payload');
    }
    
    // Log the event with full payload for debugging
    @error_log('[StripeWebhook] Received event: ' . $event['type'] . ' - ' . ($event['id'] ?? 'no-id'));
    @error_log('[StripeWebhook] Full payload: ' . $payload);
    
    // Route to appropriate handler based on event type
    $eventType = $event['type'];
    $eventData = $event['data']['object'] ?? [];
    
    switch ($eventType) {
        // Checkout events
        case 'checkout.session.completed':
            require_once __DIR__ . '/stripe_checkout_completed.php';
            handleCheckoutSessionCompleted($pdo, $eventData);
            break;
            
        case 'checkout.session.expired':
            @error_log('[StripeWebhook] Checkout session expired: ' . ($eventData['id'] ?? 'unknown'));
            break;
            
        // Payment Intent events
        case 'payment_intent.succeeded':
            require_once __DIR__ . '/stripe_payment_succeeded.php';
            handlePaymentIntentSucceeded($pdo, $eventData);
            break;
            
        case 'payment_intent.payment_failed':
            require_once __DIR__ . '/stripe_payment_failed.php';
            handlePaymentIntentFailed($pdo, $eventData);
            break;
            
        case 'payment_intent.canceled':
            @error_log('[StripeWebhook] Payment intent canceled: ' . ($eventData['id'] ?? 'unknown'));
            break;
            
        case 'payment_intent.requires_action':
            @error_log('[StripeWebhook] Payment requires action: ' . ($eventData['id'] ?? 'unknown'));
            break;
            
        // Subscription events (for future recurring billing)
        case 'invoice.payment_succeeded':
            @error_log('[StripeWebhook] Invoice payment succeeded (subscription): ' . ($eventData['id'] ?? 'unknown'));
            // Future: handle subscription invoice payments
            break;
            
        case 'invoice.payment_failed':
            @error_log('[StripeWebhook] Invoice payment failed (subscription): ' . ($eventData['id'] ?? 'unknown'));
            // Future: handle subscription payment failures
            break;
            
        case 'customer.subscription.created':
            @error_log('[StripeWebhook] Subscription created: ' . ($eventData['id'] ?? 'unknown'));
            // Future: record new subscription
            break;
            
        case 'customer.subscription.updated':
            @error_log('[StripeWebhook] Subscription updated: ' . ($eventData['id'] ?? 'unknown'));
            // Future: update subscription status
            break;
            
        case 'customer.subscription.deleted':
            @error_log('[StripeWebhook] Subscription deleted: ' . ($eventData['id'] ?? 'unknown'));
            // Future: mark subscription as cancelled
            break;
            
        // Customer events
        case 'customer.created':
            @error_log('[StripeWebhook] Customer created: ' . ($eventData['id'] ?? 'unknown'));
            break;
            
        case 'customer.updated':
            @error_log('[StripeWebhook] Customer updated: ' . ($eventData['id'] ?? 'unknown'));
            break;
            
        case 'customer.deleted':
            @error_log('[StripeWebhook] Customer deleted: ' . ($eventData['id'] ?? 'unknown'));
            break;
            
        // Refund events
        case 'charge.refunded':
            @error_log('[StripeWebhook] Charge refunded: ' . ($eventData['id'] ?? 'unknown'));
            // Future: handle refunds
            break;
            
        // Dispute events
        case 'charge.dispute.created':
            @error_log('[StripeWebhook] Dispute created: ' . ($eventData['id'] ?? 'unknown'));
            // Future: handle disputes
            break;
            
        default:
            @error_log('[StripeWebhook] Unhandled event type: ' . $eventType);
    }
    
    echo json_encode(['received' => true]);
    
} catch (Throwable $e) {
    @error_log('[StripeWebhook] Error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
