<?php
// src/controllers/stripe/stripe_webhook.php
// Handles Stripe webhook events (checkout.session.completed, etc.)

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/StripeService.php';
require_once __DIR__ . '/../../utils/notifications.php';
require_once __DIR__ . '/../../utils/webhook_logger.php';

// Get raw POST body for signature verification
$endpointName = 'stripe-webhook-legacy';
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$logId = webhook_log_insert($pdo, $endpointName, $payload, $sigHeader, $clientIp);

$signatureValid = null;
$responseCode = 200;
$errorMessage = null;

header('Content-Type: application/json');

if (!$payload) {
    http_response_code(400);
    webhook_log_update($pdo, $logId, false, 400, 'No payload');
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
        $signatureValid = true;
    } elseif ($webhookSecret) {
        // Secret configured but no signature header = reject
        throw new Exception('Missing webhook signature');
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
            handlePaymentFailed($pdo, $appConfig, $event['data']['object']);
            break;
            
        default:
            // Log unknown events but don't fail
            @error_log('[StripeWebhook] Unhandled event type: ' . $event['type']);
    }
    
    echo json_encode(['received' => true]);
    
} catch (Throwable $e) {
    @error_log('[StripeWebhook] Error: ' . $e->getMessage());
    $responseCode = 400;
    $errorMessage = $e->getMessage();
    http_response_code($responseCode);
    webhook_log_update($pdo, $logId, $signatureValid, $responseCode, $errorMessage);
    echo json_encode(['error' => $e->getMessage()]);
}

webhook_log_update($pdo, $logId, $signatureValid, $responseCode, $errorMessage);

/**
 * Handle payment failure - send alert email if enabled
 */
function handlePaymentFailed($pdo, $appConfig, $paymentIntent) {
    $piId = $paymentIntent['id'] ?? 'unknown';
    $metadata = $paymentIntent['metadata'] ?? [];
    $invoiceId = $metadata['pa_invoice_id'] ?? $metadata['invoice_id'] ?? null;
    $errorMsg = $paymentIntent['last_payment_error']['message'] ?? 'Unknown error';
    
    @error_log('[StripeWebhook] Payment failed: ' . $piId . ' - ' . $errorMsg);
    
    // Log to auto_pay_log if invoice linked
    if ($invoiceId) {
        try {
            $invStmt = $pdo->prepare('SELECT client_id FROM invoices WHERE id = ?');
            $invStmt->execute([(int)$invoiceId]);
            $clientId = (int)$invStmt->fetchColumn();
            $pdo->prepare('INSERT INTO auto_pay_log (client_id, invoice_id, amount, status, stripe_payment_intent_id, error_message) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$clientId ?: null, (int)$invoiceId, ($paymentIntent['amount'] ?? 0) / 100, 'failed', $piId, $errorMsg]);
        } catch (Throwable $e) { @error_log('[StripeWebhook] Failed to log payment failure: ' . $e->getMessage()); }
    }
    
    // Send failure alert email if enabled
    if (!empty($appConfig['payment_failure_alert']) && !empty($appConfig['from_email'])) {
        try {
            require_once __DIR__ . '/../../utils/mailer.php';
            require_once __DIR__ . '/../../utils/crypto.php';
            $smtpPass = '';
            if (!empty($appConfig['smtp_password_enc']) && is_string($appConfig['smtp_password_enc'])) {
                $encVal = $appConfig['smtp_password_enc'];
                if (strpos($encVal, 'plain::') === 0) { $smtpPass = substr($encVal, 7); }
                else { $pt = crypto_decrypt($encVal); if (is_string($pt)) { $smtpPass = $pt; } }
            }
            $mailCfg = [
                'host' => (string)($appConfig['smtp_host'] ?? ''),
                'port' => (int)($appConfig['smtp_port'] ?? 587),
                'secure' => strtolower((string)($appConfig['smtp_secure'] ?? 'tls')),
                'username' => (string)($appConfig['smtp_username'] ?? ''),
                'password' => $smtpPass,
            ];
            $fromEmail = (string)$appConfig['from_email'];
            $fromName = (string)($appConfig['from_name'] ?? ($appConfig['brand_name'] ?? 'Project Alpha'));
            
            // Get client/invoice details
            $details = '';
            if ($invoiceId) {
                $dStmt = $pdo->prepare('SELECT i.doc_number, c.name FROM invoices i LEFT JOIN clients c ON c.id = i.client_id WHERE i.id = ?');
                $dStmt->execute([(int)$invoiceId]);
                $d = $dStmt->fetch(PDO::FETCH_ASSOC);
                if ($d) $details = 'Invoice I-' . ($d['doc_number'] ?? $invoiceId) . ' for ' . ($d['name'] ?? 'Unknown');
            }
            
            $subject = 'Payment Failed' . ($details ? " — $details" : '');
            $body = '<h2>Auto-Pay Failure Alert</h2>';
            $body .= '<p>' . ($details ? "<strong>{$details}</strong><br>" : '') . 'Amount: $' . number_format(($paymentIntent['amount'] ?? 0) / 100, 2) . '</p>';
            $body .= '<p><strong>Error:</strong> ' . htmlspecialchars($errorMsg) . '</p>';
            $body .= '<p>Payment Intent: ' . htmlspecialchars($piId) . '</p>';
            
            mailer_send($mailCfg, $fromEmail, $subject, $body, $fromEmail, $fromName, ($mailCfg['username'] ?: $fromEmail));
        } catch (Throwable $e) {
            @error_log('[StripeWebhook] Failed to send payment failure alert: ' . $e->getMessage());
        }
    }
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
    $paymentIntentId = !empty($session['payment_intent']) ? (string)$session['payment_intent'] : null;
    $paymentAmount = isset($metadata['original_amount']) ? (float)$metadata['original_amount'] : $amountTotal;
    $surchargeAmount = isset($metadata['surcharge_amount']) ? (float)$metadata['surcharge_amount'] : max(0, $amountTotal - $paymentAmount);
    $paymentStatus = $session['payment_status'] ?? '';
    
    if ($paymentStatus !== 'paid') {
        @error_log('[StripeWebhook] Session not paid: ' . $paymentStatus);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get client_id from the invoice
        $invStmt = $pdo->prepare('SELECT client_id FROM invoices WHERE id = ?');
        $invStmt->execute([$invoiceId]);
        $clientId = (int)$invStmt->fetchColumn();
        
        if ($clientId <= 0) {
            @error_log('[StripeWebhook] Invoice ' . $invoiceId . ' not found or has no client - skipping');
            $pdo->rollBack();
            return;
        }
        
        // Ensure legacy Stripe columns exist
        try {
            $pdo->exec('ALTER TABLE payments ADD COLUMN stripe_session_id VARCHAR(255) NULL');
        } catch (Throwable $e) { /* column exists */ }
        try {
            $pdo->exec('ALTER TABLE payments ADD COLUMN stripe_payment_intent_id VARCHAR(255) NULL');
        } catch (Throwable $e) { /* column exists */ }
        try {
            $pdo->exec('ALTER TABLE payments ADD COLUMN surcharge_paid DECIMAL(12,2) NOT NULL DEFAULT 0');
        } catch (Throwable $e) { /* column exists */ }

        $existsStmt = $pdo->prepare('SELECT id FROM payments WHERE stripe_session_id = ? OR (stripe_payment_intent_id IS NOT NULL AND stripe_payment_intent_id = ?)');
        $existsStmt->execute([$session['id'], $paymentIntentId]);
        if ($existsStmt->fetchColumn()) {
            $pdo->rollBack();
            @error_log('[StripeWebhook] Session or PaymentIntent already recorded: ' . $session['id']);
            return;
        }
        
        // Record the payment
        $pdo->prepare('
            INSERT INTO payments (client_id, invoice_id, amount, surcharge_paid, payment_method, stripe_session_id, stripe_payment_intent_id, status, payment_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
        ')->execute([$clientId, $invoiceId, $paymentAmount, $surchargeAmount, 'stripe', $session['id'], $paymentIntentId, 'succeeded']);
        
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
        
        // Notify admin of payment via public link
        try {
            notify_admin_invoice_paid($pdo, $invoiceId, $paymentAmount, 'stripe');
        } catch (Throwable $e) {
            @error_log('[StripeWebhook] Failed to send admin notification: ' . $e->getMessage());
        }
        
    } catch (Throwable $e) {
        $pdo->rollBack();
        @error_log('[StripeWebhook] Failed to record payment: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Handle payment_intent.succeeded event
 * Primary handler for Payment Intent based payments (auto-pay, direct charges)
 */
function handlePaymentIntentSucceeded($pdo, $paymentIntent) {
    $metadata = $paymentIntent['metadata'] ?? [];
    $invoiceId = $metadata['pa_invoice_id'] ?? $metadata['invoice_id'] ?? null;
    $piId = $paymentIntent['id'] ?? null;
    
    if (!$invoiceId || !$piId) {
        @error_log('[StripeWebhook] PaymentIntent missing invoice_id or id in metadata');
        return;
    }
    
    $invoiceId = (int)$invoiceId;
    $amountTotal = ($paymentIntent['amount'] ?? 0) / 100; // Convert from cents
    $paymentAmount = isset($metadata['original_amount']) ? (float)$metadata['original_amount'] : $amountTotal;
    $surchargeAmount = isset($metadata['surcharge_amount']) ? (float)$metadata['surcharge_amount'] : max(0, $amountTotal - $paymentAmount);
    
    // Check if already recorded (idempotency)
    $existsStmt = $pdo->prepare('SELECT id FROM payments WHERE stripe_payment_intent_id = ?');
    $existsStmt->execute([$piId]);
    if ($existsStmt->fetchColumn()) {
        @error_log('[StripeWebhook] PaymentIntent already recorded: ' . $piId);
        return;
    }
    
    try {
        $pdo->beginTransaction();

        $invCheck = $pdo->prepare('SELECT id, client_id FROM invoices WHERE id = ?');
        $invCheck->execute([$invoiceId]);
        $invoice = $invCheck->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            $pdo->rollBack();
            @error_log('[StripeWebhook] Invoice ' . $invoiceId . ' not found - skipping PaymentIntent recording');
            return;
        }
        
        // Record the payment
        $isAutoPay = !empty($metadata['auto_pay']) ? 1 : 0;
        $pdo->prepare('
            INSERT INTO payments (client_id, invoice_id, amount, surcharge_paid, payment_method, stripe_payment_intent_id, auto_pay_attempt, status, payment_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
        ')->execute([(int)$invoice['client_id'], $invoiceId, $paymentAmount, $surchargeAmount, 'stripe', $piId, $isAutoPay, 'succeeded']);
        
        // Update invoice status
        $sum = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS paid FROM payments WHERE invoice_id = ? AND status = "succeeded"');
        $sum->execute([$invoiceId]);
        $paid = (float)$sum->fetchColumn();
        
        $tot = $pdo->prepare('SELECT total FROM invoices WHERE id = ?');
        $tot->execute([$invoiceId]);
        $total = (float)$tot->fetchColumn();
        
        $status = ($paid >= $total) ? 'paid' : 'partial';
        
        try {
            $pdo->prepare('UPDATE invoices SET status = ?, amount_paid = ? WHERE id = ?')->execute([$status, $paid, $invoiceId]);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE invoices SET status = ? WHERE id = ?')->execute([$status, $invoiceId]);
        }
        
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
        
        // Notify admin of payment via public link
        try {
            notify_admin_invoice_paid($pdo, $invoiceId, $paymentAmount, 'stripe');
        } catch (Throwable $e) {
            @error_log('[StripeWebhook] Failed to send admin notification: ' . $e->getMessage());
        }
        
    } catch (Throwable $e) {
        $pdo->rollBack();
        @error_log('[StripeWebhook] Failed to record PaymentIntent: ' . $e->getMessage());
        throw $e;
    }
}
