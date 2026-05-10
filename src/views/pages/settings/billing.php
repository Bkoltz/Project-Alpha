<?php
// src/views/pages/settings/billing.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
?>

<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
  <legend style="padding:0 6px;color:var(--muted)">Billing Defaults</legend>
  <label>
    <div>Net Terms (days)</div>
    <input type="number" min="0" name="net_terms" value="<?php echo htmlspecialchars((string)($appConfig['net_terms'] ?? 30)); ?>" style="width:120px;padding:10px;border-radius:8px;border:1px solid #ddd">
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

<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
  <legend style="padding:0 6px;color:var(--muted)">Credit Card Surcharge</legend>
  <div style="color:#666;font-size:0.9em;margin-bottom:12px">Configure how Stripe processing fees are handled. When client pays a portion, it's added to their invoice total.</div>
  
  <label style="display:block;margin-bottom:12px">
    <div style="margin-bottom:4px;font-weight:500">Surcharge Mode</div>
    <select name="stripe_surcharge_type" id="surchargeType" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      <option value="merchant" <?php echo ($appConfig['stripe_surcharge_type'] ?? 'merchant') === 'merchant' ? 'selected' : ''; ?>>Merchant Pays Full Fee</option>
      <option value="split" <?php echo ($appConfig['stripe_surcharge_type'] ?? '') === 'split' ? 'selected' : ''; ?>>Split 50/50</option>
      <option value="client" <?php echo ($appConfig['stripe_surcharge_type'] ?? '') === 'client' ? 'selected' : ''; ?>>Client Pays Full Fee</option>
    </select>
    <div style="font-size:0.85em;color:#666;margin-top:4px">Choose who pays the Stripe processing fee.</div>
  </label>
  
  <div id="surchargeDetails" style="display:none">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
      <label>
        <div style="margin-bottom:4px;font-weight:500">Processing Fee %</div>
        <input type="number" step="0.01" name="stripe_surcharge_percent" value="<?php echo htmlspecialchars((string)($appConfig['stripe_surcharge_percent'] ?? 2.9)); ?>" placeholder="2.9" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div style="margin-bottom:4px;font-weight:500">Fixed Fee ($)</div>
        <input type="number" step="0.01" name="stripe_surcharge_fixed" value="<?php echo htmlspecialchars((string)($appConfig['stripe_surcharge_fixed'] ?? 0.30)); ?>" placeholder="0.30" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </div>
    
    <label id="splitPercentField" style="display:block;margin-bottom:12px">
      <div style="margin-bottom:4px;font-weight:500">Client Pays What % of Fee?</div>
      <input type="number" step="1" min="0" max="100" name="stripe_surcharge_split_percent" value="<?php echo htmlspecialchars((string)($appConfig['stripe_surcharge_split_percent'] ?? 50)); ?>" placeholder="50" style="width:120px;padding:10px;border-radius:8px;border:1px solid #ddd">
      <span style="margin-left:8px">%</span>
    </label>
    
    <label style="display:block;margin-bottom:12px">
      <div style="margin-bottom:4px;font-weight:500">Surcharge Message</div>
      <textarea name="stripe_surcharge_message" rows="2" placeholder="Message shown to clients explaining the surcharge..." style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;resize:vertical;font-family:inherit"><?php echo htmlspecialchars($appConfig['stripe_surcharge_message'] ?? ''); ?></textarea>
      <div style="font-size:0.85em;color:#666;margin-top:4px">Shown on invoices when credit card surcharge applies. Leave blank for default.</div>
    </label>
    
    <div id="surchargePreview" style="padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;font-size:14px">
      <strong>Preview on $100 invoice:</strong>
      <div id="surchargePreviewText" style="margin-top:4px;color:#4b5563"></div>
    </div>
  </div>
</fieldset>

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

<script src="js/billing-logic.js" defer></script>
