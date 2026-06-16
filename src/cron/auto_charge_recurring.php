<?php
// src/cron/auto_charge_recurring.php
// Cron job to auto-charge clients for recurring/long-term invoices
// Run daily via cron

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/StripeService.php';
require_once __DIR__ . '/../utils/StripeFeeCalculator.php';

@error_log('[AutoPay] Starting auto-charge cron job at ' . date('Y-m-d H:i:s'));

try {
    $stripe = StripeService::fromAppConfig($appConfig);
    if (!$stripe) {
        @error_log('[AutoPay] Stripe not configured, skipping');
        exit(0);
    }
    
    // Find invoices that are due for auto-pay
    // Criteria:
    // 1. Invoice status is 'unpaid' or 'partial'
    // 2. Invoice type is 'recurring' or has auto_pay_enabled
    // 3. Client has stripe_customer_id and stripe_payment_method_id
    // 4. Client has auto_pay_enabled = 1
    // 5. Invoice due date has passed or is today
    
    $stmt = $pdo->prepare("
        SELECT 
            i.id AS invoice_id,
            i.total,
            i.amount_paid,
            i.due_date,
            i.status,
            c.id AS client_id,
            c.name AS client_name,
            c.email,
            c.stripe_customer_id,
            c.stripe_payment_method_id,
            c.auto_pay_enabled
        FROM invoices i
        JOIN clients c ON c.id = i.client_id
        WHERE i.status IN ('unpaid', 'partial')
        AND i.due_date <= CURDATE()
        AND c.auto_pay_enabled = 1
        AND c.stripe_customer_id IS NOT NULL
        AND c.stripe_payment_method_id IS NOT NULL
        AND i.invoice_type IN ('long_term', 'on_demand')
        AND (i.last_auto_pay_attempt IS NULL OR i.last_auto_pay_attempt < DATE_SUB(NOW(), INTERVAL 1 DAY))
        ORDER BY i.due_date ASC
        LIMIT 50
    ");
    
    $stmt->execute();
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    @error_log('[AutoPay] Found ' . count($invoices) . ' invoices ready for auto-pay');
    
    foreach ($invoices as $invoice) {
        $invoiceId = (int)$invoice['invoice_id'];
        $clientId = (int)$invoice['client_id'];
        $customerId = $invoice['stripe_customer_id'];
        $paymentMethodId = $invoice['stripe_payment_method_id'];
        $amount = (float)$invoice['total'] - (float)$invoice['amount_paid'];
        
        if ($amount <= 0) {
            @error_log('[AutoPay] Invoice ' . $invoiceId . ' already fully paid, skipping');
            continue;
        }
        
        // Calculate surcharge
        $surchargeConfig = StripeFeeCalculator::calculateSurcharge($amount, $appConfig);
        $chargeAmount = $surchargeConfig['new_total'];
        
        @error_log('[AutoPay] Processing invoice ' . $invoiceId . ' for client ' . $clientId . ' amount: $' . $amount . ' (with surcharge: $' . $chargeAmount . ')');
        
        try {
            // Create PaymentIntent for off-session payment
            $paymentIntent = $stripe->createPaymentIntentWithMetadata(
                $chargeAmount,
                'usd',
                [
                    'pa_invoice_id' => $invoiceId,
                    'pa_client_id' => $clientId,
                    'auto_pay' => 'true',
                    'original_amount' => $amount,
                    'surcharge_amount' => $surchargeConfig['client_pays']
                ],
                $customerId,
                $paymentMethodId
            );
            
            // Check if payment succeeded immediately
            if ($paymentIntent['status'] === 'succeeded') {
                // Record payment
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare('
                    INSERT INTO payments (client_id, invoice_id, amount, surcharge_paid, payment_method, stripe_payment_intent_id, auto_pay_attempt, status, payment_date) 
                    VALUES (?, ?, ?, ?, ?, ?, 1, ?, CURDATE())
                ');
                $stmt->execute([
                    $clientId,
                    $invoiceId,
                    $amount,
                    $surchargeConfig['client_pays'],
                    'stripe',
                    $paymentIntent['id'],
                    'succeeded'
                ]);
                
                // Update invoice
                $sum = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS paid FROM payments WHERE invoice_id = ? AND status = "succeeded"');
                $sum->execute([$invoiceId]);
                $paid = (float)$sum->fetchColumn();
                
                $total = (float)$invoice['total'];
                $status = ($paid >= $total) ? 'paid' : 'partial';
                
                $pdo->prepare('UPDATE invoices SET status = ?, amount_paid = ? WHERE id = ?')->execute([$status, $paid, $invoiceId]);
                
                // Log success
                $pdo->prepare('
                    INSERT INTO auto_pay_log (client_id, invoice_id, amount, status, stripe_payment_intent_id) 
                    VALUES (?, ?, ?, ?, ?)
                ')->execute([$clientId, $invoiceId, $amount, 'success', $paymentIntent['id']]);
                
                $pdo->commit();
                
                @error_log('[AutoPay] Invoice ' . $invoiceId . ' auto-paid successfully: $' . $amount);
                
            } else {
                // Payment requires action (3D Secure, etc.)
                @error_log('[AutoPay] Invoice ' . $invoiceId . ' requires additional authentication: ' . $paymentIntent['status']);
                
                // Log as failed since auto-pay can't complete without user present
                $pdo->prepare('
                    INSERT INTO auto_pay_log (client_id, invoice_id, amount, status, stripe_payment_intent_id, error_message) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ')->execute([
                    $clientId, 
                    $invoiceId, 
                    $amount, 
                    'failed', 
                    $paymentIntent['id'],
                    'Requires customer authentication: ' . $paymentIntent['status']
                ]);
            }
            
        } catch (Throwable $e) {
            @error_log('[AutoPay] Failed to charge invoice ' . $invoiceId . ': ' . $e->getMessage());
            
            // Log failure
            try {
                $pdo->prepare('
                    INSERT INTO auto_pay_log (client_id, invoice_id, amount, status, error_message) 
                    VALUES (?, ?, ?, ?, ?)
                ')->execute([
                    $clientId, 
                    $invoiceId, 
                    $amount, 
                    'failed', 
                    $e->getMessage()
                ]);
            } catch (Throwable $logError) {
                @error_log('[AutoPay] Failed to log error: ' . $logError->getMessage());
            }
        }
        
        // Update last attempt timestamp
        $pdo->prepare('UPDATE invoices SET last_auto_pay_attempt = NOW() WHERE id = ?')->execute([$invoiceId]);
    }
    
    @error_log('[AutoPay] Auto-charge cron job completed. Processed ' . count($invoices) . ' invoices');
    
} catch (Throwable $e) {
    @error_log('[AutoPay] Critical error in auto-pay cron: ' . $e->getMessage());
    exit(1);
}

exit(0);
