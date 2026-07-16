<?php

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$successMsg = !empty($_GET['created']) ? 'Catalog item created.' : (!empty($_GET['updated']) ? 'Catalog item updated.' : (!empty($_GET['deleted']) ? 'Catalog item deactivated.' : ''));
$errorMsg = (string)($_GET['error'] ?? '');
$items = $pdo->query(
    'SELECT i.*,COUNT(c.id) work_component_count FROM item_library i
     LEFT JOIN catalog_work_components c ON c.item_library_id=i.id AND c.is_active=1
     GROUP BY i.id ORDER BY i.is_active DESC,i.item_name'
)->fetchAll(PDO::FETCH_ASSOC);
$componentStmt = $pdo->prepare('SELECT * FROM catalog_work_components WHERE item_library_id=? AND is_active=1 ORDER BY display_order,id');
foreach ($items as &$item) {
    $componentStmt->execute([$item['id']]);
    $item['work_components'] = $componentStmt->fetchAll(PDO::FETCH_ASSOC);
    $bundleStmt = $pdo->prepare('SELECT child_item_library_id item_library_id,quantity FROM catalog_bundle_items WHERE bundle_item_library_id=? ORDER BY display_order,child_item_library_id');
    $bundleStmt->execute([$item['id']]);
    $item['bundle_items'] = $bundleStmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($item);
$workTypes = $pdo->query('SELECT id,name FROM work_types WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>

<section class="settings-section catalog-settings" data-item-library-page>
  <div class="page-head">
    <div><h3>Item Library</h3><p class="muted">Client pricing and internal worker compensation stay separate. Catalog changes never rewrite existing documents or Jobs.</p></div>
    <button type="button" class="btn btn-primary" data-item-library-create>+ Add catalog item</button>
  </div>

  <?php if ($successMsg): ?><div class="alert alert-success"><?= $h($successMsg) ?></div><?php endif; ?>
  <?php if ($errorMsg): ?><div class="alert alert-danger"><?= $h($errorMsg) ?></div><?php endif; ?>

  <div class="pa-table-wrap">
    <table class="pa-table">
      <thead><tr><th>Item or service</th><th>Type</th><th>Client billing</th><th>Internal work</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($items as $item): ?>
        <tr class="<?= !$item['is_active'] ? 'is-muted' : '' ?>">
          <td><strong><?= $h($item['item_name']) ?></strong><small><?= $h($item['sku'] ?: $item['description']) ?></small></td>
          <td><?= $h(ucfirst((string)$item['entry_type'])) ?></td>
          <td>$<?= number_format((float)$item['unit_price'],2) ?> / <?= $h($item['billing_unit']) ?></td>
          <td><?= (int)$item['work_component_count'] ?> component<?= (int)$item['work_component_count'] === 1 ? '' : 's' ?></td>
          <td><span class="status-pill status-pill--<?= $item['is_active'] ? 'active' : 'inactive' ?>"><?= $item['is_active'] ? 'Active' : 'Inactive' ?></span></td>
          <td class="text-right">
            <button type="button" class="btn btn-sm" data-item-library-edit data-item='<?= $h(json_encode($item, JSON_UNESCAPED_SLASHES)) ?>'>Edit</button>
            <?php if ($item['is_active']): ?><form method="post" action="/?page=settings/item-library-handler" class="inline-form" onsubmit="return confirm('Deactivate this catalog item? Existing documents and Jobs will keep their snapshots.');"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="btn btn-sm btn-danger-outline">Deactivate</button></form><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="6" class="empty-state">No catalog items yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<div id="itemModal" class="modal-backdrop" hidden>
  <div class="modal-card modal-card--wide" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-card__head"><div><h2 id="modalTitle">Add catalog item</h2><p>Define what the client buys, then optionally describe the work PA should plan internally.</p></div><button type="button" class="btn btn-sm" data-item-library-close aria-label="Close">Close</button></div>
    <form method="post" action="/?page=settings/item-library-handler" data-catalog-form>
      <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" id="formAction" name="action" value="create"><input type="hidden" id="formId" name="id"><input type="hidden" id="componentsJson" name="components_json" value="[]"><input type="hidden" id="bundleItemsJson" name="bundle_items_json" value="[]">

      <fieldset class="settings-card"><legend>General information</legend>
        <div class="settings-form-grid"><label class="field"><span class="label">Name *</span><input class="input" id="itemName" name="item_name" required maxlength="255"></label><label class="field"><span class="label">SKU</span><input class="input" id="itemSku" name="sku" maxlength="100"></label><label class="field field--wide"><span class="label">Client-facing description</span><textarea class="input" id="itemDescription" name="description" rows="3"></textarea></label><label class="field"><span class="label">Type</span><select class="input" id="entryType" name="entry_type"><option value="product">Product</option><option value="service">Service</option><option value="fee">Fee</option><option value="bundle">Bundle</option></select></label><label class="field"><span class="label">Status</span><span class="check-row"><input type="checkbox" id="isActive" name="is_active" value="1" checked> Available for new documents</span></label></div>
      </fieldset>

      <fieldset class="settings-card"><legend>Client pricing</legend>
        <div class="settings-form-grid"><label class="field"><span class="label">Unit price *</span><input class="input" type="number" id="unitPrice" name="unit_price" min="0" step="0.01" required></label><label class="field"><span class="label">Billing unit</span><select class="input" id="billingUnit" name="billing_unit"><option value="each">Each</option><option value="hour">Hour</option><option value="day">Day</option><option value="mile">Mile</option><option value="project">Project</option></select></label><label class="field"><span class="label">Tax behavior</span><select class="input" id="taxBehavior" name="tax_behavior"><option value="inherit">Use document default</option><option value="taxable">Taxable</option><option value="exempt">Tax exempt</option></select></label></div>
      </fieldset>

      <fieldset class="settings-card"><legend>Fulfillment</legend><label class="field"><span class="label">Internal fulfillment notes</span><textarea class="input" id="fulfillmentNotes" name="fulfillment_notes" rows="2" placeholder="These notes never appear on client documents."></textarea></label></fieldset>

      <fieldset class="settings-card" id="bundleContents" hidden><legend>Bundle contents</legend><p class="muted">A bundle has one client-facing price while preserving its internal product and service quantities.</p><div class="catalog-bundle-list"><?php foreach($items as $choice): if(($choice['entry_type']??'product')==='bundle')continue; ?><label class="catalog-bundle-row" data-bundle-row data-item-id="<?=(int)$choice['id']?>"><input type="checkbox" data-bundle-child value="<?=(int)$choice['id']?>"><span><?=$h($choice['item_name'])?></span><input class="input input--small" type="number" min="0.01" step="0.01" value="1" data-bundle-quantity aria-label="Bundle quantity for <?=$h($choice['item_name'])?>"></label><?php endforeach;?></div></fieldset>

      <fieldset class="settings-card"><legend>Worker compensation</legend>
        <p class="muted">Components create planned work on the Job. Selling an item never creates payable compensation by itself.</p>
        <?php if (!$workTypes): ?><div class="alert alert-info">Create a Work Type before adding worker compensation.</div><?php endif; ?>
        <div id="workComponents" class="catalog-components"></div>
        <button type="button" class="btn" data-add-work-component <?= !$workTypes ? 'disabled' : '' ?>>+ Add work component</button>
      </fieldset>

      <div class="modal-card__actions"><button type="button" class="btn" data-item-library-close>Cancel</button><button type="submit" class="btn btn-primary">Save catalog item</button></div>
    </form>
  </div>
</div>

<template id="workComponentTemplate">
  <article class="catalog-component" data-work-component>
    <input type="hidden" data-field="id"><div class="catalog-component__head"><strong data-component-number>Work component</strong><button type="button" class="btn btn-sm btn-danger-outline" data-remove-work-component>Remove</button></div>
    <div class="settings-form-grid">
      <label class="field"><span class="label">Component name *</span><input class="input" data-field="name" required></label>
      <label class="field"><span class="label">Work Type *</span><select class="input" data-field="work_type_id" required><option value="">Choose Work Type</option><?php foreach ($workTypes as $type): ?><option value="<?= (int)$type['id'] ?>"><?= $h($type['name']) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="label">Quantity</span><select class="input" data-field="quantity_behavior"><option value="per_line">Once per document line</option><option value="per_unit">For every sold unit</option><option value="fixed">Fixed quantity</option></select></label>
      <label class="field"><span class="label">Expected minutes</span><input class="input" type="number" min="0" data-field="expected_duration_minutes"></label>
      <label class="field"><span class="label">Compensation method</span><select class="input" data-field="compensation_method"><option value="nonpayable">Nonpayable / internal</option><option value="hourly">Hourly</option><option value="fixed">Fixed amount</option><option value="base_overage">Base + hourly overage</option><option value="percentage">Percentage</option></select></label>
      <label class="field" data-pay-field="amount"><span class="label">Rate or base amount</span><input class="input" type="number" min="0" step="0.0001" data-field="compensation_amount"></label>
      <label class="field" data-pay-field="included"><span class="label">Included minutes</span><input class="input" type="number" min="0" data-field="included_minutes"></label>
      <label class="field" data-pay-field="overage"><span class="label">Hourly overage rate</span><input class="input" type="number" min="0" step="0.0001" data-field="overage_rate"></label>
      <label class="field" data-pay-field="percentage"><span class="label">Percentage</span><input class="input" type="number" min="0" max="100" step="0.0001" data-field="percentage"></label>
      <label class="field" data-pay-field="basis"><span class="label">Percentage basis</span><select class="input" data-field="percentage_basis"><option value="net_line">Net line after discounts</option><option value="gross_line">Gross line before discounts</option><option value="cash_collected">Eligible cash collected</option></select></label>
      <label class="field"><span class="label">Becomes eligible when</span><select class="input" data-field="eligibility_trigger"><option value="completed_approved">Work completed and approved</option><option value="delivered">Delivered / fulfilled</option><option value="invoice_paid">Invoice paid</option><option value="manual_release">Manager releases it</option></select></label>
      <label class="field"><span class="label">Assignment</span><span class="check-row"><input type="checkbox" data-field="assignment_required" checked> Worker must be assigned</span></label>
    </div>
  </article>
</template>

<script src="<?= $h(asset_url('/assets/js/item-library.js')) ?>" defer></script>
