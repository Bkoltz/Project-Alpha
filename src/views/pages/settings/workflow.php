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
