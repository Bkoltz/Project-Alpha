<?php
// src/controllers/stripe/stripe_charge.php
// Creates a Stripe Checkout session for admin to process card payment
// Used when merchant wants to charge a card manually (e.g., customer on phone)

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/StripeService.php';

$invoiceId = (int)($_POST['invoice_id'] ?? $_GET['invoice_id'] ?? 0);
$returnUrl = $_POST['return_url'] ?? $_GET['return_url'] ?? '';

// DEBUG
@error_log('[StripeCharge] invoiceId=' . $invoiceId . ' method=' . $_SERVER['REQUEST_METHOD'] . ' GET=' . json_encode($_GET) . ' POST=' . json_encode($_POST));

if ($invoiceId <= 0) {
    @error_log('[StripeCharge] Invalid invoice_id, redirecting to home');
    header('Location: /?page=home&error=' . urlencode('Invalid invoice'));
    exit;
}

try {
    // Check if Stripe is configured
    if (!StripeService::isConfigured($appConfig)) {
        throw new Exception('Stripe is not configured. Please add your Stripe keys in Settings → Billing.');
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
        throw new Exception('This invoice cannot be charged. Status: ' . $status);
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
    $successUrl = $baseUrl . '/?page=invoice/invoice-details&id=' . $invoiceId . '&payment=success&session_id={CHECKOUT_SESSION_ID}';
    $cancelUrl = $baseUrl . '/?page=invoice/invoice-details&id=' . $invoiceId . '&payment=cancelled';
    
    // Create Stripe checkout session
    $stripe = StripeService::fromAppConfig($appConfig);
    if (!$stripe) {
        throw new Exception('Failed to initialize Stripe');
    }
    
    $brandName = $appConfig['brand_name'] ?? 'Project Alpha';
    $description = "Invoice I-{$docNumber} - {$invoice['client_name']}";
    
    $session = $stripe->createCheckoutSession(
        $amountDue,
        'usd',
        $description,
        $successUrl,
        $cancelUrl,
        [
            'pa_invoice_id' => (string)$invoiceId,
            'invoice_id' => (string)$invoiceId, // Legacy support
            'doc_number' => $docNumber,
            'charged_by' => 'admin'
        ]
    );
    
    if (empty($session['url'])) {
        throw new Exception('Failed to create payment session');
    }
    
    @error_log('[StripeCharge] Admin checkout session created for invoice ' . $invoiceId);
    
    // Redirect to Stripe Checkout
    @error_log('[StripeCharge] Redirecting to: ' . $session['url']);
    header('Location: ' . $session['url']);
    exit;
    
} catch (Throwable $e) {
    @error_log('[StripeCharge] Error: ' . $e->getMessage());
    $errorUrl = $returnUrl ?: '/?page=invoice/invoice-details&id=' . $invoiceId;
    header('Location: ' . $errorUrl . '&stripe_error=' . urlencode($e->getMessage()));
    exit;
}
