<?php
// src/views/pages/settings/billing.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
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

<fieldset id="stripeConfig" style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px;display:none">
  <legend style="padding:0 6px;color:var(--muted)">Stripe Configuration</legend>
  <div style="color:#666;font-size:0.9em;margin-bottom:12px">Configure your Stripe API keys to enable automatic payment processing. Get your keys from the Stripe dashboard.</div>
  
  <label style="display:block;margin-bottom:12px">
    <div style="margin-bottom:4px;font-weight:500">Publishable Key</div>
    <input type="text" name="stripe_publishable_key" value="<?php echo htmlspecialchars($appConfig['stripe_publishable_key'] ?? ''); ?>" placeholder="pk_live_..." style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
  </label>
  
  <label style="display:block;margin-bottom:12px">
    <div style="margin-bottom:4px;font-weight:500">Secret Key</div>
    <input type="password" name="stripe_secret_key" value="<?php echo htmlspecialchars($appConfig['stripe_secret_key'] ?? ''); ?>" placeholder="sk_live_..." style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
  </label>
  
  <label style="display:block">
    <div style="margin-bottom:4px;font-weight:500">Webhook Secret <span style="font-weight:normal;color:#666">(Optional)</span></div>
    <input type="password" name="stripe_webhook_secret" value="<?php echo htmlspecialchars($appConfig['stripe_webhook_secret'] ?? ''); ?>" placeholder="whsec_..." style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    <div style="font-size:0.85em;color:#666;margin-top:4px">Required only if you want to receive webhook events from Stripe</div>
  </label>
</fieldset>

<script src="/assets/js/billing-logic.js" defer></script>
