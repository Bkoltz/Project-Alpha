<?php
// src/views/pages/settings/terms.php
?>
<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
  <legend style="padding:0 6px;color:var(--muted)">Document Validity</legend>
  <label style="margin-bottom:8px;display:block">
    <div style="margin-bottom:6px">Documents Valid for (days)</div>
    <input type="number" min="0" name="documents_valid_days" value="<?php echo htmlspecialchars((string)($appConfig['documents_valid_days'] ?? 14)); ?>" style="width:120px;padding:10px;border-radius:8px;border:1px solid #ddd">
    <div style="margin-top:6px;color:var(--muted);font-size:13px">Number of days public document links remain valid.</div>
  </label>
</fieldset>

<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
  <legend style="padding:0 6px;color:var(--muted)">Standard Terms & Conditions</legend>
  <p style="margin:0 0 8px;color:var(--muted);font-size:13px">Default terms for quotes and regular contracts</p>
  <textarea name="terms" rows="12" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Enter your standard terms..."><?php echo htmlspecialchars($appConfig['terms'] ?? ''); ?></textarea>
</fieldset>

<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
  <legend style="padding:0 6px;color:var(--muted)">Long-term Contract Terms & Conditions</legend>
  <p style="margin:0 0 8px;color:var(--muted);font-size:13px">Specific terms for recurring/long-term service contracts (leave blank to use standard terms)</p>
  <textarea name="long_term_terms" rows="12" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Enter your long-term contract terms..."><?php echo htmlspecialchars($appConfig['long_term_terms'] ?? ''); ?></textarea>
</fieldset>

<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
  <legend style="padding:0 6px;color:var(--muted)">On-Demand Document Terms</legend>
  <p style="margin:0 0 8px;color:var(--muted);font-size:13px">Terms to include on on-demand documents (e.g., single-use invoices). Leave blank to use standard terms.</p>
  <textarea name="on_demand_terms" rows="6" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Enter on-demand terms..."><?php echo htmlspecialchars($appConfig['on_demand_terms'] ?? ''); ?></textarea>
</fieldset>
