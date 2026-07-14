<fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:16px">
  <legend style="padding:0 8px;font-weight:600">Quote Conversion</legend>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0">
    <input type="checkbox" name="quote_auto_create_contract" value="1" <?php echo !empty($appConfig['quote_auto_create_contract']) ? 'checked' : ''; ?>>
    <span><strong>Create a contract when a quote is approved</strong><br><small>The contract starts pending and follows its normal signing workflow.</small></span>
  </label>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0">
    <input type="checkbox" name="quote_auto_create_invoice" value="1" <?php echo !empty($appConfig['quote_auto_create_invoice']) ? 'checked' : ''; ?>>
    <span><strong>Create a draft invoice when a regular quote is approved</strong><br><small>The draft remains private and cannot be paid until the contract is completed.</small></span>
  </label>
</fieldset>

<fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:16px">
  <legend style="padding:0 8px;font-weight:600">Time Access &amp; Approval</legend>
  <p style="margin:0 0 12px;color:var(--muted);font-size:13px">Employees can always access their own permitted time. Team-wide time and approval access is limited to administrators and owners unless enabled here and granted through ACL permissions.</p>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0">
    <input type="checkbox" name="workforce_allow_non_admin_time_management" value="1" <?php echo !empty($appConfig['workforce_allow_non_admin_time_management']) ? 'checked' : ''; ?>>
    <span><strong>Allow non-admin time managers to view and edit team time</strong><br><small>Requires the <code>timekeeping.manage</code> permission on the role or user.</small></span>
  </label>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0">
    <input type="checkbox" name="workforce_allow_non_admin_time_approval" value="1" <?php echo !empty($appConfig['workforce_allow_non_admin_time_approval']) ? 'checked' : ''; ?>>
    <span><strong>Allow non-admin reviewers to approve time</strong><br><small>Requires the <code>approvals.review</code> permission on the role or user.</small></span>
  </label>
</fieldset>

<fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:16px">
  <legend style="padding:0 8px;font-weight:600">Mileage</legend>
  <label>
    <div><strong>Default mileage rate</strong></div>
    <input type="number" name="default_mileage_rate" step="0.001" min="0" required value="<?php echo htmlspecialchars(number_format((float)($appConfig['default_mileage_rate'] ?? 0.670), 3, '.', '')); ?>" style="width:100%;max-width:220px;margin-top:6px;padding:10px;border-radius:8px;border:1px solid #ddd">
    <small style="display:block;margin-top:5px;color:var(--muted)">Used for new mileage entries. Each entry can still override the rate.</small>
  </label>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:14px 0 10px">
    <input type="checkbox" name="default_mileage_include_return_trip" value="1" <?php echo !array_key_exists('default_mileage_include_return_trip', $appConfig) || !empty($appConfig['default_mileage_include_return_trip']) ? 'checked' : ''; ?>>
    <span><strong>New mileage entries include a return trip</strong><br><small>The entered distance is treated as one way, and the mileage log records both directions.</small></span>
  </label>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0">
    <input type="checkbox" name="default_mileage_bill_return_trip" value="1" <?php echo !empty($appConfig['default_mileage_bill_return_trip']) ? 'checked' : ''; ?>>
    <span><strong>Bill return-trip mileage by default</strong><br><small>When off, a round trip is fully logged but only the outbound miles are billed. This can be overridden on each entry.</small></span>
  </label>
</fieldset>

<fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:16px">
  <legend style="padding:0 8px;font-weight:600">Invoice Delivery</legend>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0">
    <input type="checkbox" name="invoice_auto_email_on_contract_complete" value="1" <?php echo !empty($appConfig['invoice_auto_email_on_contract_complete']) ? 'checked' : ''; ?>>
    <span><strong>Email a finalized regular invoice when its contract is completed</strong><br><small>Monthly project child invoices remain inside the project statement.</small></span>
  </label>
  <label style="display:flex;gap:10px;align-items:flex-start;margin:10px 0">
    <input type="checkbox" name="invoice_auto_email_on_generate" value="1" <?php echo !empty($appConfig['invoice_auto_email_on_generate']) ? 'checked' : ''; ?>>
    <span><strong>Email scheduled long-term invoices when generated</strong><br><small>This generates and emails an invoice. It never charges a saved card.</small></span>
  </label>
</fieldset>

<div style="padding:12px 14px;border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:8px">
  On-demand and manual invoices always ask whether to save a draft or finalize and send.
</div>
