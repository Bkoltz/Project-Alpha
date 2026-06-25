<?php
// src/views/pages/settings/terms.php
?>
<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
  <legend style="padding:0 6px;color:var(--muted)">Per-Organization Terms</legend>
  <!-- Display-only toggle: this does NOT change multi_brand_enabled (System tab controls that). It only reveals the per-org editors on this page. -->
  <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer">
    <input type="checkbox" id="termsOrgToggle" value="1" <?php echo !empty($appConfig['multi_brand_enabled']) ? 'checked' : ''; ?> style="margin-top:3px;width:18px;height:18px">
    <div><span style="font-weight:600">Edit terms per organization</span>
    <div style="margin-top:4px;color:var(--muted);font-size:12px">When enabled below, organization-specific terms override the global terms on documents for that organization.</div></div>
  </label>
</fieldset>

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
  <textarea name="terms" rows="12" class="input" placeholder="Enter your standard terms..."><?php echo htmlspecialchars($appConfig['terms'] ?? ''); ?></textarea>
</fieldset>

<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
  <legend style="padding:0 6px;color:var(--muted)">Long-term Contract Terms & Conditions</legend>
  <p style="margin:0 0 8px;color:var(--muted);font-size:13px">Specific terms for recurring/long-term service contracts (leave blank to use standard terms)</p>
  <textarea name="long_term_terms" rows="12" class="input" placeholder="Enter your long-term contract terms..."><?php echo htmlspecialchars($appConfig['long_term_terms'] ?? ''); ?></textarea>
</fieldset>

<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
  <legend style="padding:0 6px;color:var(--muted)">On-Demand Document Terms</legend>
  <p style="margin:0 0 8px;color:var(--muted);font-size:13px">Terms to include on on-demand documents (e.g., single-use invoices). Leave blank to use standard terms.</p>
  <textarea name="on_demand_terms" rows="6" class="input" placeholder="Enter on-demand terms..."><?php echo htmlspecialchars($appConfig['on_demand_terms'] ?? ''); ?></textarea>
</fieldset>

<?php
  $__primaryOrgIdT = (int)$pdo->query('SELECT MIN(id) FROM organizations')->fetchColumn();
  $__orgsT = $pdo->prepare('SELECT id, name, brand_terms, brand_long_term_terms, brand_on_demand_terms FROM organizations WHERE id <> ? ORDER BY name');
  $__orgsT->execute([$__primaryOrgIdT]);
  $__orgsT = $__orgsT->fetchAll(PDO::FETCH_ASSOC);
?>
<div id="perOrgTermsEditors" style="<?php echo !empty($appConfig['multi_brand_enabled']) ? '' : 'display:none'; ?>">
  <p style="margin:4px 0 8px;color:var(--muted);font-size:12px">Per-organization terms. Leave blank to use the global terms above.</p>
  <?php if (empty($__orgsT)): ?>
    <p style="margin:4px 0;color:var(--muted);font-size:12px">No additional organizations yet.</p>
  <?php endif; ?>
  <?php foreach ($__orgsT as $o): ?>
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:12px"><legend><?php echo htmlspecialchars($o['name']); ?> — Terms</legend>
      <label>Standard terms<textarea name="org_<?php echo (int)$o['id']; ?>_brand_terms" rows="8" style="width:100%"><?php echo htmlspecialchars($o['brand_terms'] ?? ''); ?></textarea></label>
      <label>Long-term terms<textarea name="org_<?php echo (int)$o['id']; ?>_brand_long_term_terms" rows="8" style="width:100%"><?php echo htmlspecialchars($o['brand_long_term_terms'] ?? ''); ?></textarea></label>
      <label>On-demand terms<textarea name="org_<?php echo (int)$o['id']; ?>_brand_on_demand_terms" rows="6" style="width:100%"><?php echo htmlspecialchars($o['brand_on_demand_terms'] ?? ''); ?></textarea></label>
    </fieldset>
  <?php endforeach; ?>
</div>
