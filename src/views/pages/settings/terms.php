<?php
// src/views/pages/settings/terms.php
?>

<?php
$__primaryId = (int)$pdo->query('SELECT MIN(id) FROM organizations')->fetchColumn();
$__termsOrgs = $pdo->query('SELECT id, name, brand_terms, brand_long_term_terms, brand_on_demand_terms FROM organizations ORDER BY (id = ' . (int)$__primaryId . ') DESC, name')->fetchAll(PDO::FETCH_ASSOC);
?>

<?php if (!empty($appConfig['multi_brand_enabled'])): ?>
<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-bottom:16px">
  <legend style="padding:0 6px;color:var(--muted)">Brand</legend>
  <div id="termsBrandSwitcher" style="display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach ($__termsOrgs as $to): $isP = ((int)$to['id'] === $__primaryId); ?>
      <button type="button" class="terms-brand-btn" data-org-id="<?php echo (int)$to['id']; ?>" style="padding:8px 14px;border-radius:8px;border:1px solid #ddd;background:#fff;cursor:pointer"><?php echo htmlspecialchars($to['name']); ?><?php echo $isP ? ' (Main)' : ''; ?></button>
    <?php endforeach; ?>
  </div>
</fieldset>
<?php endif; ?>

<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
  <legend style="padding:0 6px;color:var(--muted)">Document Validity</legend>
  <label style="margin-bottom:8px;display:block">
    <div style="margin-bottom:6px">Documents Valid for (days)</div>
    <input type="number" min="0" name="documents_valid_days" value="<?php echo htmlspecialchars((string)($appConfig['documents_valid_days'] ?? 14)); ?>" style="width:120px;padding:10px;border-radius:8px;border:1px solid #ddd">
    <div style="margin-top:6px;color:var(--muted);font-size:13px">Number of days public document links remain valid.</div>
  </label>
</fieldset>

<?php if (empty($appConfig['multi_brand_enabled'])): ?>
  <?php foreach ($__termsOrgs as $to):
    $isP = ((int)$to['id'] === $__primaryId);
    if (!$isP) continue;
  ?>
  <div class="terms-editor-set" data-org-id="<?php echo (int)$to['id']; ?>" style="display:block">
    <h3 style="margin:8px 0">Terms for <?php echo htmlspecialchars($to['name']); ?> (main brand)</h3>
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:8px"><legend>Standard Terms &amp; Conditions</legend>
      <textarea name="terms" rows="12" class="input"><?php echo htmlspecialchars($appConfig['terms'] ?? ''); ?></textarea>
    </fieldset>
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:8px"><legend>Long-term Contract Terms</legend>
      <textarea name="long_term_terms" rows="12" class="input"><?php echo htmlspecialchars($appConfig['long_term_terms'] ?? ''); ?></textarea>
    </fieldset>
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:8px"><legend>On-Demand Document Terms</legend>
      <textarea name="on_demand_terms" rows="6" class="input"><?php echo htmlspecialchars($appConfig['on_demand_terms'] ?? ''); ?></textarea>
    </fieldset>
  </div>
  <?php endforeach; ?>
<?php else: ?>
  <?php foreach ($__termsOrgs as $to):
    $isP = ((int)$to['id'] === $__primaryId);
    $disp = $isP ? 'block' : 'none';
  ?>
  <div class="terms-editor-set" data-org-id="<?php echo (int)$to['id']; ?>" style="display:<?php echo $disp; ?>">
    <h3 style="margin:8px 0">Terms for <?php echo htmlspecialchars($to['name']); ?><?php echo $isP ? ' (main brand)' : ''; ?></h3>
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:8px"><legend>Standard Terms &amp; Conditions</legend>
      <textarea name="<?php echo $isP ? 'terms' : 'org_'.(int)$to['id'].'_brand_terms'; ?>" rows="12" class="input"><?php echo htmlspecialchars($isP ? ($appConfig['terms'] ?? '') : ($to['brand_terms'] ?? '')); ?></textarea>
    </fieldset>
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:8px"><legend>Long-term Contract Terms</legend>
      <textarea name="<?php echo $isP ? 'long_term_terms' : 'org_'.(int)$to['id'].'_brand_long_term_terms'; ?>" rows="12" class="input"><?php echo htmlspecialchars($isP ? ($appConfig['long_term_terms'] ?? '') : ($to['brand_long_term_terms'] ?? '')); ?></textarea>
    </fieldset>
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:8px"><legend>On-Demand Document Terms</legend>
      <textarea name="<?php echo $isP ? 'on_demand_terms' : 'org_'.(int)$to['id'].'_brand_on_demand_terms'; ?>" rows="6" class="input"><?php echo htmlspecialchars($isP ? ($appConfig['on_demand_terms'] ?? '') : ($to['brand_on_demand_terms'] ?? '')); ?></textarea>
    </fieldset>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
