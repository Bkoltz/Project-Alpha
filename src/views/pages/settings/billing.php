<?php
// src/views/pages/settings/billing.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../services/StripeService.php';

$stripeSecretConfigured = StripeService::hasSecretKey($appConfig ?? []);
$stripeWebhookConfigured = !empty($appConfig['_stripe_webhook_secret']) || !empty($appConfig['stripe_webhook_secret_enc']);
$stripePanelConfigured = $stripeSecretConfigured || $stripeWebhookConfigured || !empty($appConfig['stripe_publishable_key']);
?>

<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
  <legend style="padding:0 6px;color:var(--muted)">Billing Defaults</legend>
  <label>
    <div>Net Terms (days)</div>
    <input type="number" min="0" name="net_terms_days" value="<?php echo htmlspecialchars((string)($appConfig['net_terms_days'] ?? 30)); ?>" style="width:120px;padding:10px;border-radius:8px;border:1px solid #ddd">
  </label>
  <div style="margin-top:12px"></div>
  <?php $paymentMethods = (array)($appConfig['payment_methods'] ?? ['card', 'cash', 'bank_transfer']); ?>
  <div style="margin-bottom:8px">
    <div style="margin-bottom:6px">Payment Methods</div>
    <div id="pmList" style="display:flex;flex-direction:column;gap:6px">
      <?php foreach ($paymentMethods as $pm): ?>
        <div class="pm-item" style="display:flex;align-items:center;gap:8px">
          <input type="hidden" name="payment_methods_backup[]" value="<?php echo htmlspecialchars($pm); ?>">
          <span style="padding:8px 10px;border:1px solid #ddd;border-radius:6px;background:#fafafa"><?php echo htmlspecialchars($pm); ?></span>
          <button type="button" class="pm-remove" style="margin-left:auto;padding:6px 8px;border-radius:6px;border:1px solid #ddd;background:#fff">Remove</button>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:8px;margin-top:8px">
      <select id="pmSelect" style="padding:8px;border-radius:8px;border:1px solid #ddd">
        <option value="stripe">Stripe</option>
        <option value="bank_transfer">Bank Transfer</option>
        <option value="cash">Cash</option>
        <option value="Check">Check</option>
        <option value="other">Other...</option>
      </select>
      <input id="pmCustom" placeholder="Custom method" style="padding:8px;border-radius:8px;border:1px solid #ddd;flex:1;display:none">
      <button type="button" id="pmAdd" style="padding:8px 10px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff">Add</button>
    </div>

    <!-- Hidden JSON payload for server processing; fallback backup textarea kept for backward compatibility -->
    <input type="hidden" name="payment_methods_json" id="paymentMethodsJson" value="<?php echo htmlspecialchars(json_encode($paymentMethods)); ?>">
    <textarea name="payment_methods" rows="3" style="width:100%;padding:8px;border-radius:6px;border:1px solid #eee;margin-top:8px;display:none"><?php echo htmlspecialchars(implode("\n", $paymentMethods)); ?></textarea>
  </div>
</fieldset>

<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
  <legend style="padding:0 6px;color:var(--muted)">Review Request</legend>
  <label>
    <div style="margin-bottom:4px">Review Link <span style="color:#666;font-weight:normal">(Optional)</span></div>
    <input type="url" name="review_link" value="<?php echo htmlspecialchars($appConfig['review_link'] ?? ''); ?>" placeholder="https://g.page/your-business/review" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    <div style="font-size:0.85em;color:#666;margin-top:4px">If set, this link will appear on invoices to encourage clients to leave a review.</div>
  </label>
</fieldset>

<div style="margin-top:20px;padding:16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;font-size:14px">
  <strong>💡 Looking for Tax Rates?</strong>
  <div style="margin-top:8px;color:#1e40af">Tax rate management has been moved to the <a href="/?page=settings&tab=taxes" style="color:var(--nav-accent);font-weight:600">Taxes</a> tab for better organization.</div>
</div>

<fieldset id="stripeConfig" data-configured="<?php echo $stripePanelConfigured ? '1' : '0'; ?>" style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px;display:none">
  <legend style="padding:0 6px;color:var(--muted)">Stripe Configuration</legend>
  <div style="color:#666;font-size:0.9em;margin-bottom:12px">Configure your Stripe API keys to enable automatic payment processing. Get your keys from the Stripe dashboard.</div>
  
  <label style="display:block;margin-bottom:12px">
    <div style="margin-bottom:4px;font-weight:500">Publishable Key</div>
    <input type="text" name="stripe_publishable_key" value="<?php echo htmlspecialchars($appConfig['stripe_publishable_key'] ?? ''); ?>" placeholder="pk_live_..." style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
  </label>
  
  <label style="display:block;margin-bottom:12px">
    <div style="margin-bottom:4px;font-weight:500">Secret Key</div>
    <input type="password" name="stripe_secret_key" value="" placeholder="<?php echo $stripeSecretConfigured ? 'Saved - enter a new key to replace' : 'sk_live_...'; ?>" autocomplete="new-password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    <div style="font-size:0.85em;color:<?php echo $stripeSecretConfigured ? '#166534' : '#92400e'; ?>;margin-top:4px">
      <?php echo $stripeSecretConfigured ? 'Secret key is saved on the server.' : 'Secret key is not configured.'; ?>
    </div>
  </label>
  
  <label style="display:block">
    <div style="margin-bottom:4px;font-weight:500">Webhook Secret <span style="font-weight:normal;color:#666">(Optional)</span></div>
    <input type="password" name="stripe_webhook_secret" value="" placeholder="<?php echo $stripeWebhookConfigured ? 'Saved - enter a new secret to replace' : 'whsec_...'; ?>" autocomplete="new-password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    <div style="font-size:0.85em;color:#666;margin-top:4px">Required only if you want to receive webhook events from Stripe</div>
  </label>
</fieldset>

<fieldset id="surchargeConfig" style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px;">
  <legend style="font-weight:600;padding:0 8px;">Credit Card Surcharge</legend>
  <div class="form-group" style="margin-top:12px;">
    <label style="font-size:0.85rem;color:#6c757d;display:block;margin-bottom:0.35rem;">Surcharge Mode</label>
    <select name="stripe_surcharge_type" class="input" style="width:100%;padding:0.5rem;border-radius:6px;border:1px solid #ddd;">
      <option value="merchant" <?php echo ($appConfig['stripe_surcharge_type'] ?? 'merchant') === 'merchant' ? 'selected' : ''; ?>>Merchant absorbs fee (no surcharge)</option>
      <option value="split" <?php echo ($appConfig['stripe_surcharge_type'] ?? 'merchant') === 'split' ? 'selected' : ''; ?>>Split fee with client</option>
      <option value="client" <?php echo ($appConfig['stripe_surcharge_type'] ?? 'merchant') === 'client' ? 'selected' : ''; ?>>Client pays full fee</option>
    </select>
    <span class="help-text" style="display:block;margin-top:0.35rem;font-size:0.8rem;">Merchant: you absorb the processing fee. Split: client pays a portion. Client: client pays the full fee (capped at actual merchant rate).</span>
  </div>
  <div class="form-group" style="margin-top:12px;">
    <label style="font-size:0.85rem;color:#6c757d;display:block;margin-bottom:0.35rem;">Split Percentage (%)</label>
    <input type="number" name="stripe_surcharge_split_percent" value="<?php echo htmlspecialchars($appConfig['stripe_surcharge_split_percent'] ?? 50); ?>" min="0" max="100" step="1" class="input" style="width:100%;padding:0.5rem;border-radius:6px;border:1px solid #ddd;">
    <span class="help-text" style="display:block;margin-top:0.35rem;font-size:0.8rem;">In split mode, what percentage of the fee does the client pay? (0-100, default 50)</span>
  </div>
  <div class="form-group" style="margin-top:12px;">
    <label style="font-size:0.85rem;color:#6c757d;display:block;margin-bottom:0.35rem;">Custom Surcharge Message</label>
    <textarea name="stripe_surcharge_message" class="input" style="width:100%;padding:0.5rem;border-radius:6px;border:1px solid #ddd;min-height:60px;" placeholder="Optional message shown to clients about the surcharge"><?php echo htmlspecialchars($appConfig['stripe_surcharge_message'] ?? ''); ?></textarea>
  </div>
  <?php
  // Show synced merchant rate if available
  $syncedRate = $appConfig['stripe_effective_rate_pct'] ?? null;
  $syncedAt = $appConfig['stripe_effective_rate_synced_at'] ?? null;
  if ($syncedRate !== null):
  ?>
  <div style="margin-top:12px;padding:8px 12px;background:#f0f7ff;border-radius:6px;font-size:0.85rem;color:#1e40af;">
    <strong>Synced Merchant Rate:</strong> <?php echo htmlspecialchars($syncedRate); ?>%
    <?php if ($syncedAt): ?> (last synced: <?php echo htmlspecialchars($syncedAt); ?>)<?php endif; ?>
    <br><span style="font-size:0.78rem;color:#6b7280;">This is your actual blended Stripe rate from the last 30 days of transactions. The client surcharge is capped at this rate.</span>
  </div>
  <?php endif; ?>
  <div style="margin-top:8px;font-size:0.78rem;color:#6b7280;">
    <strong>Note:</strong> Surcharging requires Visa registration (30-day notice) before enabling client/split mode. Debit cards are never surcharged (automatically refunded). The surcharge is capped at your actual merchant processing rate.
  </div>
</fieldset>

<script src="/assets/js/billing-logic.js" defer></script>
