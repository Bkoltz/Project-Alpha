<?php
// src/controllers/stripe/stripe_checkout.php
// Initiates a Stripe Checkout Session for invoice payment via public token

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/StripeService.php';

header('Content-Type: text/html; charset=UTF-8');

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';

if ($token === '') {
    http_response_code(400);
    echo '<main><div style="max-width:500px;margin:60px auto;padding:20px;text-align:center;"><h1>Invalid Request</h1><p>No payment token provided.</p></div></main>';
    exit;
}

try {
    // Validate the token and get invoice info
    // Try to get expire_when_paid column
    try {
        $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked, expire_when_paid FROM public_links WHERE token=? LIMIT 1');
        $st->execute([$token]);
        $linkRow = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Fallback without expire_when_paid
        $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked FROM public_links WHERE token=? LIMIT 1');
        $st->execute([$token]);
        $linkRow = $st->fetch(PDO::FETCH_ASSOC);
        if ($linkRow) {
            $linkRow['expire_when_paid'] = 0;
        }
    }
    
    if (!$linkRow) {
        throw new Exception('Link not found');
    }
    
    // Check if revoked
    if ((int)($linkRow['revoked'] ?? 0) === 1) {
        throw new Exception('Link has expired');
    }
    
    // Check expiration based on type
    $expireWhenPaid = !empty($linkRow['expire_when_paid']);
    if ($expireWhenPaid) {
        // For expire_when_paid, check invoice status
        // (will be checked again below, but we need to validate the link first)
    } else {
        // Date-based expiration - only check if expires_at is set
        if (!empty($linkRow['expires_at']) && strtotime((string)$linkRow['expires_at']) < time()) {
            throw new Exception('Link has expired');
        }
    }
    
    if ($linkRow['document_type'] !== 'invoice') {
        throw new Exception('Payment is only available for invoices');
    }
    
    $invoiceId = (int)$linkRow['document_id'];
    
    // Check if Stripe is configured
    if (!StripeService::isConfigured($appConfig)) {
        throw new Exception('Online payment is not configured. Please contact us for alternative payment methods.');
    }
    
    // Get invoice details
    $invSt = $pdo->prepare('
        SELECT i.*, c.name as client_name, c.email as client_email 
        FROM invoices i 
        JOIN clients c ON c.id = i.client_id 
        WHERE i.id = ?
    ');
    $invSt->execute([$invoiceId]);
    $invoice = $invSt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        throw new Exception('Invoice not found');
    }
    
    // Check invoice status
    $status = strtolower($invoice['status'] ?? '');
    if (!in_array($status, ['unpaid', 'partial'], true)) {
        throw new Exception('This invoice cannot be paid online. Status: ' . $status);
    }
    
    // Calculate amount due from payments table for accuracy
    $total = (float)($invoice['total'] ?? 0);
    $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND status = "succeeded"');
    $paidStmt->execute([$invoiceId]);
    $amountPaid = (float)$paidStmt->fetchColumn();
    $amountDue = $total - $amountPaid;
    
    if ($amountDue <= 0) {
        throw new Exception('This invoice has already been paid in full.');
    }
    
    // Build URLs
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '' && !empty($appConfig['app_host'])) {
        $host = (string)$appConfig['app_host'];
    }
    if ($host === '') {
        $host = 'localhost';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $baseUrl = $scheme . '://' . $host;
    
    $docNumber = $invoice['doc_number'] ?? $invoiceId;
    $successUrl = $baseUrl . '/?page=stripe-success&token=' . rawurlencode($token) . '&session_id={CHECKOUT_SESSION_ID}';
    $cancelUrl = $baseUrl . '/?page=public-doc&token=' . rawurlencode($token) . '&cancelled=1';
    
    // Create Stripe checkout session
    $stripe = StripeService::fromAppConfig($appConfig);
    if (!$stripe) {
        throw new Exception('Failed to initialize payment service');
    }
    
    $brandName = $appConfig['brand_name'] ?? 'Project Alpha';
    $description = "Invoice I-{$docNumber} from {$brandName}";
    
    $session = $stripe->createCheckoutSession(
        $amountDue,
        'usd', // TODO: Make currency configurable
        $description,
        $successUrl,
        $cancelUrl,
        [
            'pa_invoice_id' => (string)$invoiceId,
            'invoice_id' => (string)$invoiceId, // Legacy support
            'doc_number' => $docNumber,
            'token' => $token
        ]
    );
    
    if (empty($session['url'])) {
        throw new Exception('Failed to create payment session');
    }
    
    // Log the checkout initiation
    @error_log('[StripeCheckout] Session created for invoice ' . $invoiceId . ': ' . ($session['id'] ?? 'unknown'));
    
    // Redirect to Stripe Checkout
    header('Location: ' . $session['url']);
    exit;
    
} catch (Throwable $e) {
    @error_log('[StripeCheckout] Error: ' . $e->getMessage());
    http_response_code(400);
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Payment Error</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f9fafb; margin: 0; padding: 20px; }
            .error-wrap { max-width: 500px; margin: 60px auto; padding: 32px; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); text-align: center; }
            h1 { color: #dc2626; font-size: 24px; margin: 0 0 16px; }
            p { color: #4b5563; line-height: 1.6; margin: 0 0 20px; }
            .back-link { display: inline-block; padding: 10px 20px; background: #3b82f6; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 500; }
            .back-link:hover { background: #2563eb; }
        </style>
    </head>
    <body>
        <div class="error-wrap">
            <h1>Payment Error</h1>
            <p><?php echo htmlspecialchars($e->getMessage()); ?></p>
            <?php if ($token): ?>
            <a href="/?page=public-doc&token=<?php echo htmlspecialchars(rawurlencode($token)); ?>" class="back-link">Back to Invoice</a>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}
