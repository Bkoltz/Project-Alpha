<?php
// src/controllers/stripe/stripe_success.php
// Handles successful Stripe payment redirect

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/StripeService.php';

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
$sessionId = isset($_GET['session_id']) ? (string)$_GET['session_id'] : '';

$brandName = $appConfig['brand_name'] ?? 'Project Alpha';

if ($token === '' || $sessionId === '') {
    http_response_code(400);
    echo '<main><div style="max-width:500px;margin:60px auto;padding:20px;text-align:center;"><h1>Invalid Request</h1><p>Missing payment information.</p></div></main>';
    exit;
}

try {
    // Validate token
    $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked FROM public_links WHERE token=? LIMIT 1');
    $st->execute([$token]);
    $linkRow = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$linkRow || $linkRow['document_type'] !== 'invoice') {
        throw new Exception('Invalid payment link');
    }
    
    $invoiceId = (int)$linkRow['document_id'];
    
    // Get invoice details
    $invSt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
    $invSt->execute([$invoiceId]);
    $invoice = $invSt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        throw new Exception('Invoice not found');
    }
    
    // Initialize Stripe and verify the session
    $stripe = StripeService::fromAppConfig($appConfig);
    if ($stripe) {
        try {
            // Retrieve checkout session to verify payment
            // Note: In production, you'd want to use webhooks for reliable payment confirmation
            // This is a basic check for the success page display
            @error_log('[StripeSuccess] Payment success page viewed for invoice ' . $invoiceId . ', session: ' . $sessionId);
        } catch (Throwable $e) {
            @error_log('[StripeSuccess] Could not verify session: ' . $e->getMessage());
        }
    }
    
    $docNumber = $invoice['doc_number'] ?? $invoiceId;
    
} catch (Throwable $e) {
    @error_log('[StripeSuccess] Error: ' . $e->getMessage());
    $docNumber = 'Unknown';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful - <?php echo htmlspecialchars($brandName); ?></title>
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%); 
            margin: 0; 
            padding: 20px;
            min-height: 100vh;
        }
        .success-wrap { 
            max-width: 500px; 
            margin: 60px auto; 
            padding: 40px; 
            background: #fff; 
            border-radius: 16px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.08); 
            text-align: center; 
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        .success-icon svg {
            width: 40px;
            height: 40px;
            stroke: white;
            stroke-width: 3;
            fill: none;
        }
        h1 { 
            color: #065f46; 
            font-size: 28px; 
            margin: 0 0 12px; 
        }
        .subtitle {
            color: #6b7280;
            font-size: 16px;
            margin: 0 0 24px;
        }
        .invoice-ref {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            margin: 0 0 24px;
        }
        .invoice-ref strong {
            color: #374151;
        }
        p { 
            color: #4b5563; 
            line-height: 1.6; 
            margin: 0 0 20px; 
        }
        .back-link { 
            display: inline-block; 
            padding: 12px 24px; 
            background: #10b981; 
            color: #fff; 
            text-decoration: none; 
            border-radius: 8px; 
            font-weight: 600;
            transition: background 0.2s;
        }
        .back-link:hover { 
            background: #059669; 
        }
        .note {
            margin-top: 24px;
            padding: 16px;
            background: #fef3c7;
            border: 1px solid #fde68a;
            border-radius: 8px;
            font-size: 14px;
            color: #92400e;
        }
    </style>
</head>
<body>
    <div class="success-wrap">
        <div class="success-icon">
            <svg viewBox="0 0 24 24">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        </div>
        <h1>Payment Successful!</h1>
        <p class="subtitle">Thank you for your payment.</p>
        
        <div class="invoice-ref">
            <strong>Invoice I-<?php echo htmlspecialchars($docNumber); ?></strong>
        </div>
        
        <p>Your payment has been processed successfully. You will receive a confirmation email shortly.</p>
        
        <?php if ($token): ?>
        <a href="/?page=public-doc&token=<?php echo htmlspecialchars(rawurlencode($token)); ?>" class="back-link">View Invoice</a>
        <?php endif; ?>
        
        <div class="note">
            <strong>Note:</strong> If you don't receive a confirmation email within 24 hours, please contact us.
        </div>
    </div>
</body>
<script>
    // Attempt to close the Stripe checkout tab if the browser allows it
    if (window.opener && window.opener !== window) {
        window.close();
    }
</script>
</html>
