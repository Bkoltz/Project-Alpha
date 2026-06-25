<?php
// src/controllers/public_doc.php
// Render a public, tokenized view of a document without requiring auth
// TODO: We need to add more public views. One for contract, so a client can upload a signed contract via link/portal on public_contract_action. Use a mix of PHP and twig for page views.
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
if (!rate_limit_check($pdo, 'public_doc', 30, 60)) {
  http_response_code(429);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!DOCTYPE html><html><head><title>Rate limited</title></head><body><h1>Rate limited</h1></body></html>';
  exit;
}
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if ($token === '') {
  http_response_code(400);
  echo '<main><div class="auth-wrap"><h1>Invalid link</h1><p>This link is not valid.</p></div></main>';
  exit;
}

try {
  // First, ensure the expire_when_paid column exists (migration)
  try {
    $pdo->exec("ALTER TABLE public_links ADD COLUMN expire_when_paid TINYINT(1) NOT NULL DEFAULT 0");
  } catch (Throwable $e) { /* column already exists */ }
  
  // Try query with expire_when_paid column
  try {
    $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked, expire_when_paid FROM public_links WHERE token=? LIMIT 1');
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    // Fallback query without expire_when_paid column
    $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked FROM public_links WHERE token=? LIMIT 1');
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $row['expire_when_paid'] = 0; // Default to false
    }
  }
  
  if (!$row) { throw new Exception('notfound'); }
  
  // Check if revoked
  if ((int)($row['revoked'] ?? 0) === 1) { throw new Exception('expired'); }
  
  // Check expiration - either by date or by payment status
  $expireWhenPaid = !empty($row['expire_when_paid']);
  if ($expireWhenPaid) {
    // For expire_when_paid links, check if the invoice is paid
    if ($row['document_type'] === 'invoice') {
      $invCheck = $pdo->prepare('SELECT status FROM invoices WHERE id = ?');
      $invCheck->execute([(int)$row['document_id']]);
      $invStatus = strtolower($invCheck->fetchColumn() ?: '');
      if ($invStatus === 'paid' || $invStatus === 'void') {
        throw new Exception('expired');
      }
    }
  } else {
    // For date-based expiration
    if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) {
      throw new Exception('expired');
    }
  }

  $type = (string)$row['document_type'];
  $rid = (int)$row['document_id'];

  require_once __DIR__ . '/../../utils/Branding.php';
  if ($type==='quote') { $oq=$pdo->prepare('SELECT organization_id FROM quotes WHERE id=?'); }
  elseif ($type==='contract') { $oq=$pdo->prepare('SELECT organization_id FROM contracts WHERE id=?'); }
  else { $oq=$pdo->prepare('SELECT organization_id FROM invoices WHERE id=?'); }
  $oq->execute([$rid]);
  $docOrgId=(int)$oq->fetchColumn();
  $brandInfo  = Branding::resolve($appConfig, $docOrgId);
  $brandTerms = Branding::resolveTerms($appConfig, $docOrgId);

  if (!defined('PUBLIC_VIEW')) define('PUBLIC_VIEW', true);
  $_GET['id'] = (string)$rid;

  // Optional notice banners
  $notice = isset($_GET['ok']) && $_GET['ok'] === '1';
  $err = isset($_GET['error']) ? (string)$_GET['error'] : '';

  // For invoice links, ensure invoice remains in public-viewable states
  if ($type === 'invoice') {
    $vs = $pdo->prepare('SELECT status FROM invoices WHERE id=? LIMIT 1');
    $vs->execute([$rid]);
    $invStatus = strtolower((string)($vs->fetchColumn() ?: ''));
    if ($invStatus === '') {
      throw new Exception('invoice_not_found:' . $rid);
    }
    if (!in_array($invStatus, ['unpaid', 'partial'], true)) {
      throw new Exception('invoice_status_blocked:' . $invStatus);
    }
  }

  // Determine whether to show actions or upload form in the view
  $showActions = false; $showUpload = false;
  if ($type === 'quote') {
    try {
      $qs = $pdo->prepare('SELECT status FROM quotes WHERE id=? LIMIT 1');
      $qs->execute([$rid]);
      $status = (string)($qs->fetchColumn() ?: '');
      if ($status === 'pending') { $showActions = true; }
    } catch (Throwable $e) { /* ignore */ }
  }
  if ($type === 'contract') {
    try {
      $cs = $pdo->prepare('SELECT status FROM contracts WHERE id=? LIMIT 1');
      $cs->execute([$rid]);
      $cstat = (string)($cs->fetchColumn() ?: '');
      if ($cstat === 'pending') { $showUpload = true; }
    } catch (Throwable $e) { /* ignore */ }
  }

  // Prefer Twig template if available, otherwise include PHP view wrapper
  $twigTemplate = __DIR__ . '/../../views/public/doc-template.twig';
  $phpView = __DIR__ . '/../../views/public/doc-wrapper.php';
  if (is_file($twigTemplate) && is_file(__DIR__ . '/../../vendor/autoload.php')) {
    // Attempt to render Twig if installed (non-fatal)
    try {
      require_once __DIR__ . '/../../vendor/autoload.php';
      // Code is correct, but it throws an error, ignore for now
      $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../../views');
      $twig = new \Twig\Environment($loader);
      // If rendering a public quote, prepare the quote data and pass it into Twig
      $templateVars = ['type'=>$type, 'id'=>$rid, 'token'=>$token, 'notice'=>$notice, 'error'=>$err,
        'showActions'=>$showActions, 'showUpload'=>$showUpload, 'appConfig'=>$appConfig,
        'brandInfo'=>$brandInfo, 'brandTerms'=>$brandTerms
      ];

      // Pre-render payment-options banner for invoices (mirrors doc-wrapper.php)
      $paymentBannerHtml = '';
      if ($type === 'invoice') {
        require_once __DIR__ . '/../../services/StripeService.php';
        require_once __DIR__ . '/../../utils/StripeFeeCalculator.php';
        if (StripeService::isConfigured($appConfig)) {
          $invSt = $pdo->prepare('SELECT status, total, original_amount, surcharge_amount, surcharge_type FROM invoices WHERE id = ?');
          $invSt->execute([$rid]);
          $invD = $invSt->fetch(PDO::FETCH_ASSOC);
          if ($invD) {
            $paidSt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND status = "succeeded"');
            $paidSt->execute([$rid]);
            $amountPaid = (float)$paidSt->fetchColumn();
            $invStatus = strtolower($invD['status'] ?? '');
            $calculatedAmountDue = (float)($invD['total'] ?? 0) - $amountPaid;
            $showPayButton = in_array($invStatus, ['unpaid', 'partial'], true) && $calculatedAmountDue > 0;
            $surchargeInfo = StripeFeeCalculator::calculateSurcharge($calculatedAmountDue, $appConfig);
            if ($showPayButton) {
              $amountDue = $calculatedAmountDue;
              ob_start();
?>
  <div class="payment-section">
    <div class="payment-header">
      <div class="payment-title">Payment Options</div>
      <div class="payment-amount-due">
        <?php if ($surchargeInfo && $surchargeInfo['client_pays'] > 0): ?>
          <span class="original-amount">$<?php echo number_format($calculatedAmountDue, 2); ?></span>
          <span class="total-due">$<?php echo number_format($surchargeInfo['new_total'], 2); ?></span>
          <span class="due-label">total due</span>
        <?php else: ?>
          <span class="total-due">$<?php echo number_format($amountDue, 2); ?></span>
          <span class="due-label">due</span>
        <?php endif; ?>
      </div>
    </div>

    <?php if (StripeFeeCalculator::isSurchargeEnabled($appConfig) && $surchargeInfo && $surchargeInfo['client_pays'] > 0): ?>
    <div class="surcharge-notice">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"></circle>
        <line x1="12" y1="8" x2="12" y2="12"></line>
        <line x1="12" y1="16" x2="12.01" y2="16"></line>
      </svg>
      <div class="surcharge-text">
        <div style="font-weight:600;margin-bottom:4px">
          <strong>Credit Card Processing Fee:</strong>
          A surcharge of $<?php echo number_format($surchargeInfo['client_pays'], 2); ?> will be added to your payment.
          <?php echo htmlspecialchars($surchargeInfo['display_text']); ?>
        </div>
        <div style="margin-top:4px;font-size:12px;">
          <small>This fee does not apply to debit cards, bank transfers, or checks.</small>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="payment-options">
      <!-- Credit Card (Primary) -->
      <a href="/?page=stripe-checkout&token=<?php echo htmlspecialchars(rawurlencode($token)); ?>" class="payment-option primary">
        <div class="payment-option-icon">💳</div>
        <div class="payment-option-details">
          <div class="payment-option-name">Pay by Credit Card</div>
          <div class="payment-option-desc">Secure online payment via Stripe</div>
        </div>
        <div class="payment-option-arrow">→</div>
      </a>

      <?php
      // Show check option if enabled
      $paymentMethods = (array)($appConfig['payment_methods'] ?? ['card', 'cash', 'bank_transfer']);
      $hasCheck = false;
      $hasCash = false;

      foreach ($paymentMethods as $pm) {
        $pmLower = strtolower(trim($pm));
        if ($pmLower === 'check') $hasCheck = true;
        if ($pmLower === 'cash') $hasCash = true;
      }

      if ($hasCheck):
        $payeeName = ($brandInfo['from_name'] ?? '') ?: ($brandInfo['brand_name'] ?? 'Project Alpha');
        $payeeAddress = [];
        if (!empty($brandInfo['from_address_line1'])) $payeeAddress[] = $brandInfo['from_address_line1'];
        if (!empty($appConfig['from_address'])) $payeeAddress[] = $appConfig['from_address'];
        if (!empty($brandInfo['from_city'])) $payeeAddress[] = $brandInfo['from_city'];
        if (!empty($brandInfo['from_state'])) $payeeAddress[] = $brandInfo['from_state'];
        if (!empty($brandInfo['from_postal'])) $payeeAddress[] = $brandInfo['from_postal'];
        if (empty($payeeAddress) && !empty($appConfig['from_zip'])) $payeeAddress[] = $appConfig['from_zip'];
      ?>
      <div class="payment-option">
        <div class="payment-option-icon">📄</div>
        <div class="payment-option-details">
          <div class="payment-option-name">Pay by Check</div>
          <div class="payment-option-desc">
            Make check payable to: <strong><?php echo htmlspecialchars($payeeName); ?></strong>
            <?php if (!empty($payeeAddress)): ?>
            <br><span style="color:#6b7280"><?php echo htmlspecialchars(implode(', ', $payeeAddress)); ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($hasCash): ?>
      <div class="payment-option">
        <div class="payment-option-icon">💵</div>
        <div class="payment-option-details">
          <div class="payment-option-name">Pay with Cash</div>
          <div class="payment-option-desc">Contact us to arrange an in-person payment</div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="payment-footer">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
      </svg>
      All credit card payments are secure and encrypted
    </div>
  </div>

  <style>
    .payment-section {
      margin-bottom: 24px;
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      overflow: hidden;
    }
    .payment-header {
      background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
      color: #fff;
      padding: 24px;
      text-align: center;
    }
    .payment-title {
      font-size: 14px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      opacity: 0.9;
      margin-bottom: 8px;
    }
    .payment-amount-due {
      display: flex;
      align-items: baseline;
      justify-content: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .total-due {
      font-size: 36px;
      font-weight: 700;
    }
    .due-label {
      font-size: 16px;
      opacity: 0.8;
    }
    .original-amount {
      font-size: 18px;
      text-decoration: line-through;
      opacity: 0.7;
    }
    .surcharge-notice {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      padding: 12px 16px;
      background: #fffbeb;
      border-bottom: 1px solid #fcd34d;
      color: #92400e;
      font-size: 13px;
    }
    .surcharge-notice svg {
      flex-shrink: 0;
      margin-top: 1px;
    }
    .payment-options {
      padding: 16px;
    }
    .payment-option {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 16px;
      border: 1.5px solid #e5e7eb;
      border-radius: 10px;
      margin-bottom: 10px;
      transition: all 0.2s;
    }
    .payment-option:last-child {
      margin-bottom: 0;
    }
    .payment-option.primary {
      background: #eff6ff;
      border-color: #3b82f6;
      text-decoration: none;
      color: inherit;
      cursor: pointer;
    }
    .payment-option.primary:hover {
      background: #dbeafe;
      border-color: #2563eb;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(59,130,246,0.15);
    }
    .payment-option-icon {
      font-size: 24px;
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #f3f4f6;
      border-radius: 10px;
      flex-shrink: 0;
    }
    .payment-option.primary .payment-option-icon {
      background: #dbeafe;
    }
    .payment-option-details {
      flex: 1;
      text-align: left;
    }
    .payment-option-name {
      font-weight: 600;
      color: #111827;
      font-size: 15px;
    }
    .payment-option-desc {
      font-size: 13px;
      color: #6b7280;
      margin-top: 2px;
    }
    .payment-option-arrow {
      font-size: 20px;
      color: #3b82f6;
      font-weight: 600;
    }
    .payment-footer {
      padding: 12px 16px;
      background: #f9fafb;
      border-top: 1px solid #e5e7eb;
      text-align: center;
      font-size: 12px;
      color: #6b7280;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }
  </style>
<?php
              $paymentBannerHtml = ob_get_clean();
            }
          }
        }
      }
      $templateVars['paymentBannerHtml'] = $paymentBannerHtml;

      if ($type === 'quote') {
        try {
          $idParam = (int)$rid;
          $stmt = $pdo->prepare('SELECT q.*, c.name client_name, o.name AS client_org, c.email client_email, c.phone client_phone, c.address_line1, c.address_line2, c.city, c.state, c.postal_code, c.country FROM quotes q JOIN clients c ON c.id=q.client_id LEFT JOIN organizations o ON o.id=c.organization_id WHERE q.id=?');
          $stmt->execute([$idParam]);
          $quote = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($quote) {
            $itemsSt = $pdo->prepare('SELECT item, description, quantity, unit_price, line_total FROM quote_items WHERE quote_id=?');
            $itemsSt->execute([$idParam]);
            $items = $itemsSt->fetchAll(PDO::FETCH_ASSOC);

            // Prepare sender info
            $fromName = ($brandInfo['from_name'] ?? '') ?: ($brandInfo['brand_name'] ?? ($appConfig['brand_name'] ?? 'Project Alpha'));
            $fromPhone = $brandInfo['from_phone'] ?? ($appConfig['from_phone'] ?? '');
            $fromEmail = $brandInfo['from_email'] ?? ($appConfig['from_email'] ?? '');

            // Resolve terms: project-level -> quote -> app
            $termsText = '';
            if (!empty($quote['project_code'])) {
              try {
                $pm = $pdo->prepare('SELECT terms FROM project_meta WHERE project_code=?');
                $pm->execute([$quote['project_code']]);
                $pt = (string)$pm->fetchColumn();
                if (trim($pt) !== '') { $termsText = trim($pt); }
              } catch (Throwable $_e) { /* ignore */ }
            }
            if ($termsText === '') { $termsText = trim((string)($quote['terms'] ?? '')); }
            if ($termsText === '') { $termsText = trim((string)($brandTerms['terms'] ?? ($appConfig['terms'] ?? ''))); }

            // Deposit calculation
            $depositType = $quote['deposit_type'] ?? 'none';
            $depositValue = (float)($quote['deposit_amount'] ?? 0);
            $quoteTotal = (float)($quote['total'] ?? 0);
            $depositCalc = 0.0;
            if ($depositType === 'percent') {
              $depositCalc = max(0, min(100, $depositValue)) * $quoteTotal / 100;
            } elseif ($depositType === 'fixed') {
              $depositCalc = $depositValue;
            }

            $fulfillmentDate = $quote['fulfillment_date'] ?? null;
            $showDepositInfo = $depositType !== 'none' && $depositCalc > 0;
            $showFulfillmentDate = !empty($fulfillmentDate);

            $scopeText = trim((string)($quote['scope'] ?? ''));
            $scopeEnabled = !isset($appConfig['quote_scope_enabled']) || !empty($appConfig['quote_scope_enabled']);

            // Minimal logo handling: pass configured logo and let template decide how to render
            $logoConf = trim((string)($brandInfo['logo_path'] ?? ($appConfig['logo_path'] ?? '')));

            $templateVars['quote'] = $quote;
            $templateVars['items'] = $items;
            $templateVars['fromName'] = $fromName;
            $templateVars['fromPhone'] = $fromPhone;
            $templateVars['fromEmail'] = $fromEmail;
            $templateVars['termsText'] = $termsText;
            $templateVars['depositCalc'] = $depositCalc;
            $templateVars['showDepositInfo'] = $showDepositInfo;
            $templateVars['showFulfillmentDate'] = $showFulfillmentDate;
            $templateVars['fulfillmentDate'] = $fulfillmentDate;
            $templateVars['scopeText'] = $scopeText;
            $templateVars['scopeEnabled'] = $scopeEnabled;
            $templateVars['logoPath'] = $logoConf;
          }
        } catch (Throwable $_e) { /* ignore and render wrapper */ }
      }

      echo $twig->render('public/doc-template.twig', $templateVars);
    } catch (Throwable $_t) {
      require $phpView;
    }
  } else {
    require $phpView;
  }
} catch (Throwable $e) {
  http_response_code(404);
  @error_log('[PublicDoc] Error: ' . $e->getMessage() . ' Token: ' . $token);
  echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Link Expired</title>
  <style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f3f4f6;margin:0;padding:40px 20px;}
  .wrap{max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:40px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,0.1);}
  h1{color:#1f2937;font-size:24px;margin:0 0 12px;}p{color:#6b7280;margin:0 0 24px;line-height:1.6;}
  .icon{width:64px;height:64px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;}
  </style></head><body>
  <div class="wrap">
    <div class="icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
    <h1>Link Expired</h1>
    <p>This link has expired or is no longer valid. Please contact us for a new link.</p>
  </div></body></html>';
  exit;
}
