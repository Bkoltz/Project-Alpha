<?php

$workTypes = $pdo->query(
    'SELECT wt.*,
            COALESCE(wtb.default_treatment, "undecided") AS billing_treatment,
            wtb.default_billing_rate,
            COALESCE(wtb.currency, wt.currency, "USD") AS billing_currency
     FROM work_types wt
     LEFT JOIN work_type_billing_defaults wtb ON wtb.work_type_id = wt.id
     ORDER BY wt.is_active DESC, wt.name'
)->fetchAll(PDO::FETCH_ASSOC);
$h = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

$editId = max(0, (int)($_GET['edit_work_type'] ?? 0));
$editing = null;
foreach ($workTypes as $workType) {
    if ((int)$workType['id'] === $editId) {
        $editing = $workType;
        break;
    }
}

$form = $editing ?? [
    'id' => 0,
    'name' => '',
    'code' => '',
    'description' => '',
    'is_active' => 1,
    'billing_treatment' => 'undecided',
    'default_billing_rate' => '',
    'billing_currency' => 'USD',
    'default_compensation_method' => 'nonpayable',
    'default_amount' => '',
    'default_base_minutes' => '',
    'default_overage_rate' => '',
    'default_percentage' => '',
    'default_percentage_basis' => 'net_line',
    'default_eligibility_trigger' => 'completed_approved',
    'currency' => 'USD',
];

$billingLabels = [
    'undecided' => 'Decide when time is reviewed',
    'internal' => 'Internal / do not bill the client',
    'fixed_price_included' => 'Included in an agreed fixed price',
    'hourly' => 'Bill the client by the hour',
];
$compensationLabels = [
    'nonpayable' => 'No worker compensation',
    'hourly' => 'Hourly pay',
    'fixed' => 'Fixed amount',
    'base_overage' => 'Base amount plus hourly overage',
    'percentage' => 'Percentage of an eligible client amount',
];
?>

<div class="settings-managed-list">
  <div class="settings-list-header">
    <div>
      <h3>Work Types</h3>
      <p>A Work Type describes what a person did. Client billing and worker compensation are separate defaults and can be reviewed independently on each entry.</p>
    </div>
  </div>

  <form method="post"
        action="/?page=settings/workforce-catalog-handler"
        class="settings-primary-form"
        data-settings-track-dirty>
    <input type="hidden" name="csrf" value="<?=$h(csrf_token())?>">
    <input type="hidden" name="action" value="save-work-type">
    <input type="hidden" name="return_tab" value="work-types">
    <input type="hidden" name="id" value="<?=(int)$form['id']?>">

    <fieldset>
      <legend><?=$editing ? 'Edit Work Type' : 'New Work Type'?></legend>
      <p class="muted">This classification is shared by time tracking, assignments, billing review, and compensation review.</p>
      <div class="settings-form-grid">
        <label class="field">
          <span class="label">Name</span>
          <input class="input" name="name" maxlength="190" value="<?=$h($form['name'])?>" required>
        </label>
        <label class="field">
          <span class="label">Code</span>
          <input class="input" name="code" maxlength="64" value="<?=$h($form['code'])?>" placeholder="DEER_RECOVERY">
          <small>Used internally for reporting and integrations.</small>
        </label>
        <label class="field field--wide">
          <span class="label">Description</span>
          <textarea class="input" name="description" rows="3" maxlength="5000"><?=$h($form['description'])?></textarea>
        </label>
        <label class="check-row">
          <input type="checkbox" name="is_active" value="1" <?=!empty($form['is_active']) ? 'checked' : ''?>>
          <span>Available for new time entries and assignments</span>
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Client billing default</legend>
      <p class="muted">This controls how the business normally charges a client for this work. It does not determine what a worker earns.</p>
      <div class="settings-form-grid">
        <label class="field">
          <span class="label">Default billing treatment</span>
          <select class="input" name="billing_treatment">
            <?php foreach ($billingLabels as $value => $label): ?>
              <option value="<?=$h($value)?>" <?=$form['billing_treatment'] === $value ? 'selected' : ''?>><?=$h($label)?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span class="label">Default client hourly rate</span>
          <input class="input" type="number" min="0" step="0.0001" name="billing_rate" value="<?=$h($form['default_billing_rate'])?>" placeholder="Leave blank to decide later">
          <small>Used only when the billing treatment is hourly.</small>
        </label>
        <label class="field">
          <span class="label">Billing currency</span>
          <input class="input" name="billing_currency" maxlength="3" value="<?=$h($form['billing_currency'])?>" required>
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Worker compensation default</legend>
      <p class="muted">This controls what an eligible employee or contractor normally earns. Owners remain nonpayable, and worker- or assignment-specific rules may override this default.</p>
      <div class="settings-form-grid">
        <label class="field">
          <span class="label">Compensation method</span>
          <select class="input" name="method">
            <?php foreach ($compensationLabels as $value => $label): ?>
              <option value="<?=$h($value)?>" <?=$form['default_compensation_method'] === $value ? 'selected' : ''?>><?=$h($label)?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span class="label">Hourly rate, fixed amount, or base amount</span>
          <input class="input" type="number" min="0" step="0.0001" name="amount" value="<?=$h($form['default_amount'])?>">
        </label>
        <label class="field">
          <span class="label">Minutes included in base amount</span>
          <input class="input" type="number" min="0" name="included_minutes" value="<?=$h($form['default_base_minutes'])?>">
        </label>
        <label class="field">
          <span class="label">Hourly overage rate</span>
          <input class="input" type="number" min="0" step="0.0001" name="overage_rate" value="<?=$h($form['default_overage_rate'])?>">
        </label>
        <label class="field">
          <span class="label">Percentage</span>
          <input class="input" type="number" min="0" max="100" step="0.0001" name="percentage" value="<?=$h($form['default_percentage'])?>">
        </label>
        <label class="field">
          <span class="label">Percentage basis</span>
          <select class="input" name="percentage_basis">
            <?php foreach (['net_line' => 'Net client line', 'gross_line' => 'Gross client line', 'cash_collected' => 'Eligible cash collected'] as $value => $label): ?>
              <option value="<?=$h($value)?>" <?=$form['default_percentage_basis'] === $value ? 'selected' : ''?>><?=$h($label)?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span class="label">Becomes eligible when</span>
          <select class="input" name="eligibility_trigger">
            <?php foreach (['completed_approved' => 'Work is completed and approved', 'delivered' => 'Work is delivered or fulfilled', 'invoice_paid' => 'Client invoice is paid', 'manual_release' => 'A manager releases it'] as $value => $label): ?>
              <option value="<?=$h($value)?>" <?=$form['default_eligibility_trigger'] === $value ? 'selected' : ''?>><?=$h($label)?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span class="label">Compensation currency</span>
          <input class="input" name="compensation_currency" maxlength="3" value="<?=$h($form['currency'])?>" required>
        </label>
      </div>
    </fieldset>

    <div class="settings-save-bar" data-settings-save-bar>
      <p class="settings-save-status" aria-live="polite" data-settings-save-status><?=$editing ? 'Editing '.$h($form['name']) : 'Create a reusable Work Type'?></p>
      <div class="settings-save-actions">
        <a class="btn settings-cancel-button" data-settings-cancel href="/?page=settings&amp;tab=work-types">Cancel</a>
        <button class="btn btn-primary" type="submit"><?=$editing ? 'Save Work Type' : 'Add Work Type'?></button>
      </div>
    </div>
  </form>

  <div class="settings-card">
    <div>
      <h3>Existing Work Types</h3>
      <p class="muted">Deactivating a Work Type keeps historical time, billing, and compensation records intact.</p>
    </div>
    <div class="pa-table-wrap">
      <table class="pa-table settings-action-table">
        <thead>
          <tr>
            <th>Work Type</th>
            <th>Client billing default</th>
            <th>Worker compensation default</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($workTypes as $type): ?>
            <tr>
              <td><strong><?=$h($type['name'])?></strong><small><?=$h($type['code'])?></small></td>
              <td>
                <?=$h($billingLabels[$type['billing_treatment']] ?? $type['billing_treatment'])?>
                <?php if ($type['billing_treatment'] === 'hourly'): ?>
                  <small><?=$type['default_billing_rate'] === null ? 'Rate decided during review' : $h($type['billing_currency']).' '.number_format((float)$type['default_billing_rate'], 2).'/hr'?></small>
                <?php endif; ?>
              </td>
              <td>
                <?=$h($compensationLabels[$type['default_compensation_method']] ?? $type['default_compensation_method'])?>
                <small><?=$h(str_replace('_', ' ', ucfirst((string)$type['default_eligibility_trigger'])))?></small>
              </td>
              <td><?=$type['is_active'] ? 'Active' : 'Inactive'?></td>
              <td>
                <div class="settings-action-row">
                  <a class="btn btn-sm" href="/?page=settings&amp;tab=work-types&amp;edit_work_type=<?=(int)$type['id']?>">Edit</a>
                  <form method="post" action="/?page=settings/workforce-catalog-handler" class="inline-form">
                    <input type="hidden" name="csrf" value="<?=$h(csrf_token())?>">
                    <input type="hidden" name="action" value="set-work-type-status">
                    <input type="hidden" name="return_tab" value="work-types">
                    <input type="hidden" name="id" value="<?=(int)$type['id']?>">
                    <input type="hidden" name="status" value="<?=$type['is_active'] ? 'inactive' : 'active'?>">
                    <button class="btn btn-sm <?=$type['is_active'] ? '' : 'btn-primary'?>" type="submit"><?=$type['is_active'] ? 'Deactivate' : 'Activate'?></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$workTypes): ?>
            <tr><td colspan="5">No Work Types yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
