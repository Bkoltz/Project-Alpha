<?php
require_once __DIR__.'/../../../config/db.php';
require_once __DIR__.'/../../../utils/csrf.php';
require_once __DIR__.'/../../../utils/document_pricing_adjustments.php';
$pricingEnabled=pricing_adjustments_enabled($pdo);
$pricingSchemaReady=pricing_adjustment_schema_available($pdo);
$pricingOrganizations=[];$pricingDefinitions=[];
if($pricingSchemaReady){
  try{$pricingOrganizations=$pdo->query('SELECT id,name FROM organizations ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){}
  try{$pricingDefinitions=$pdo->query("SELECT d.*,o.name organization_name,(SELECT COUNT(*) FROM project_pricing_adjustment_assignments p WHERE p.adjustment_definition_id=d.id)+(SELECT COUNT(*) FROM contract_pricing_adjustment_assignments c WHERE c.adjustment_definition_id=d.id) assignment_count FROM pricing_adjustment_definitions d LEFT JOIN organizations o ON o.id=d.organization_id ORDER BY d.is_active DESC,d.scope_type,d.name,d.id")->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){$pricingSchemaReady=false;}
}
$h=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<div class="pricing-settings" data-pricing-settings>
  <?php if(!$pricingEnabled): ?>
    <div class="settings-state-panel" role="status"><h3>Pricing adjustments are off</h3><p>This optional feature is disabled. Existing document pricing continues unchanged, and no adjustment can be assigned or applied.</p></div>
  <?php elseif(!$pricingSchemaReady): ?>
    <div class="settings-state-panel" role="alert"><h3>Database update required</h3><p>Pricing adjustments are enabled but the required schema is unavailable. Apply the current migrations before managing adjustments.</p></div>
  <?php else: ?>
    <div class="pricing-settings-intro"><div><h3>Reusable adjustments</h3><p>Create installation-wide or customer-specific percentage adjustments. Assignment happens on a Project or Contract; document overrides remain explicit and audited.</p></div><span class="pricing-settings-count"><?php echo count($pricingDefinitions); ?> total</span></div>

    <div class="pricing-definition-list" aria-label="Pricing adjustment definitions">
      <?php if(!$pricingDefinitions): ?><div class="settings-state-panel"><h3>No adjustments yet</h3><p>Create one below. Nothing is assigned automatically.</p></div><?php endif; ?>
      <?php foreach($pricingDefinitions as $definition): ?>
        <article class="pricing-definition-card<?php echo empty($definition['is_active'])?' is-inactive':''; ?>">
          <div class="pricing-definition-heading"><div><span class="pricing-scope-badge"><?php echo $definition['scope_type']==='installation'?'Installation':'Customer'; ?></span><?php if(empty($definition['is_active'])):?><span class="pricing-inactive-badge">Inactive</span><?php endif;?><h4><?php echo $h($definition['name']); ?></h4><p><?php echo $definition['scope_type']==='installation'?'Available to every customer':$h($definition['organization_name']??'Unknown customer'); ?> - <?php echo (int)$definition['assignment_count']; ?> assignment<?php echo (int)$definition['assignment_count']===1?'':'s'; ?></p></div><strong><?php echo $h(rtrim(rtrim(number_format((float)$definition['percentage_rate'],4,'.',''),'0'),'.')); ?>%</strong></div>
          <?php if(!empty($definition['is_active'])): ?>
          <div class="pricing-definition-actions-wrap">
          <form method="post" action="/?page=settings/pricing-adjustments-handler" class="pricing-definition-form">
            <input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="definition_id" value="<?php echo (int)$definition['id']; ?>">
            <div class="pricing-form-grid"><label><span>Name</span><input name="name" maxlength="150" required value="<?php echo $h($definition['name']); ?>"></label><label><span>Percentage</span><input type="number" name="percentage_rate" min="0.0001" max="100" step="0.0001" required value="<?php echo $h($definition['percentage_rate']); ?>"></label><label><span>Starts</span><input type="date" name="effective_from" value="<?php echo $h($definition['effective_from']); ?>"></label><label><span>Ends</span><input type="date" name="effective_until" value="<?php echo $h($definition['effective_until']); ?>"></label></div>
            <button class="btn btn-primary" type="submit">Save changes</button>
          </form>
          <form method="post" action="/?page=settings/pricing-adjustments-handler" onsubmit="return confirm('Deactivate this pricing adjustment? Existing document snapshots will remain unchanged.');"><input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="definition_id" value="<?php echo (int)$definition['id']; ?>"><button class="btn" type="submit">Deactivate</button></form>
          </div>
          <?php else: ?><p class="pricing-history-note">Retained for audit and historical document snapshots. Inactive adjustments cannot be assigned.</p><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>

    <section class="pricing-create-card"><h3>Create pricing adjustment</h3><p>Creating an adjustment does not change any existing Project, Contract, quote, or invoice.</p>
      <form method="post" action="/?page=settings/pricing-adjustments-handler" class="pricing-create-form" data-pricing-create-form>
        <input type="hidden" name="csrf" value="<?php echo $h(csrf_token()); ?>"><input type="hidden" name="action" value="create">
        <div class="pricing-form-grid"><label><span>Scope</span><select name="scope_type" data-pricing-scope><option value="installation">Installation</option><option value="customer">Customer</option></select></label><label data-pricing-customer hidden><span>Customer</span><select name="organization_id"><option value="0">Select customer</option><?php foreach($pricingOrganizations as $organization):?><option value="<?php echo (int)$organization['id']; ?>"><?php echo $h($organization['name']); ?></option><?php endforeach;?></select></label><label><span>Name</span><input name="name" maxlength="150" required placeholder="Example: Preferred customer"></label><label><span>Percentage</span><input type="number" name="percentage_rate" min="0.0001" max="100" step="0.0001" required placeholder="10"></label><label><span>Starts (optional)</span><input type="date" name="effective_from"></label><label><span>Ends (optional)</span><input type="date" name="effective_until"></label></div>
        <button class="btn btn-primary" type="submit">Create adjustment</button>
      </form>
    </section>
  <?php endif; ?>
</div>
<script src="<?php echo $h(asset_url('/assets/js/pricing-adjustments.js')); ?>" defer></script>
