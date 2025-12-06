<?php
// src/views/pages/settings/terms.php
?>
<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
  <legend style="padding:0 6px;color:var(--muted)">General Settings</legend>
  <label style="margin-bottom:8px">
    <div>Documents Valid for (days)</div>
    <input type="number" min="0" name="documents_valid_days" value="<?php echo htmlspecialchars((string)($appConfig['documents_valid_days'] ?? 14)); ?>" style="width:120px;padding:10px;border-radius:8px;border:1px solid #ddd">
  </label>
  <label style="display:block;margin-bottom:8px"><input type="checkbox" name="quotes_show_terms" value="1" <?php echo (!isset($appConfig['quotes_show_terms']) || (int)($appConfig['quotes_show_terms']) === 1) ? 'checked' : ''; ?>> Show terms on Quotes</label>
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
