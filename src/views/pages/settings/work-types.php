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
      <h3>Work Activities</h3>
      <p>A Work Activity describes what a person did. It classifies tracked time and can supply separate defaults for client billing and worker compensation.</p>
    </div>
  </div>

  <div class="settings-card settings-work-type-guide">
    <div><h3>How the Service Library and Work Activities fit together</h3><p class="muted">A service describes what the client receives; an activity describes what a worker did.</p></div>
    <div class="settings-work-type-guide__steps">
      <article><strong>1. Client service</strong><span>Add a Service Library service, fee, or package to a quote, contract, or invoice.</span></article>
      <article><strong>2. Worker activity</strong><span>Track what a worker actually did with a Work Activity, such as 3D Modeling or 2D Mapping.</span></article>
      <article><strong>3. Reusable connection</strong><span>A service links to one or more reusable Work Activities for time, Job planning, billing, and assignments.</span></article>
    </div>
    <p class="settings-work-type-guide__note"><strong>Hourly billing:</strong> manually adding an hourly service to a document uses the Service Library price. Adding confirmed tracked time to an invoice uses the linked service-activity rate after any project or client override. Fixed-price work should be marked as included so tracked time does not create a second client charge.</p>
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
      <legend><?=$editing ? 'Edit Work Activity' : 'New Work Activity'?></legend>
      <p class="muted">This activity is shared by time tracking, assignments, billing review, and compensation review. Creating a Work Activity does not create a client-facing service.</p>
      <div class="settings-form-grid">
        <label class="field">
          <span class="label">Name</span>
          <input class="input" name="name" maxlength="190" value="<?=$h($form['name'])?>" required>
          <small>Use the activity name a worker will recognize when recording time, such as 3D Modeling.</small>
        </label>
        <label class="field">
          <span class="label">Code</span>
          <input class="input" name="code" maxlength="64" value="<?=$h($form['code'])?>" placeholder="DEER_RECOVERY">
          <small>Used internally for reporting and integrations.</small>
        </label>
        <label class="field field--wide">
          <span class="label">Description</span>
          <textarea class="input" name="description" rows="3" maxlength="5000"><?=$h($form['description'])?></textarea>
          <small>Explain what belongs in this Work Activity so time is classified consistently.</small>
        </label>
        <label class="check-row">
          <input type="checkbox" name="is_active" value="1" <?=!empty($form['is_active']) ? 'checked' : ''?>>
          <span>Available for new time entries and assignments</span>
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Client billing default</legend>
      <p class="muted">This is the fallback treatment when someone records this Work Activity without a more specific Service Library rule. It does not determine what a worker earns.</p>
      <div class="settings-form-grid">
        <label class="field">
          <span class="label">Default billing treatment</span>
          <select class="input" name="billing_treatment">
            <?php foreach ($billingLabels as $value => $label): ?>
              <option value="<?=$h($value)?>" <?=$form['billing_treatment'] === $value ? 'selected' : ''?>><?=$h($label)?></option>
            <?php endforeach; ?>
          </select>
          <small>Choose hourly only when this time should become a separate invoice charge. Choose included for work already covered by a service or package price.</small>
        </label>
        <label class="field">
          <span class="label">Default client hourly rate</span>
          <input class="input" type="number" min="0" step="0.0001" name="billing_rate" value="<?=$h($form['default_billing_rate'])?>" placeholder="Leave blank to decide later">
          <small>Used only for hourly tracked time. A project rate overrides a client rate, which overrides this rate; the global fallback is used last.</small>
        </label>
        <label class="field">
          <span class="label">Billing currency</span>
          <input class="input" name="billing_currency" maxlength="3" value="<?=$h($form['billing_currency'])?>" required>
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Worker compensation default</legend>
      <p class="muted">This controls what an eligible employee or contractor normally earns for this kind of work. It is independent of what the client is charged. Owners remain nonpayable, and assignment- or worker-specific rules may override this default.</p>
      <div class="settings-form-grid">
        <label class="field">
          <span class="label">Compensation method</span>
          <select class="input" name="method">
            <?php foreach ($compensationLabels as $value => $label): ?>
              <option value="<?=$h($value)?>" <?=$form['default_compensation_method'] === $value ? 'selected' : ''?>><?=$h($label)?></option>
            <?php endforeach; ?>
          </select>
          <small>Choose the normal pay calculation for eligible workers performing this Work Activity.</small>
        </label>
        <label class="field">
          <span class="label">Hourly rate, fixed amount, or base amount</span>
          <input class="input" type="number" min="0" step="0.0001" name="amount" value="<?=$h($form['default_amount'])?>">
          <small>Hourly uses this as the pay rate; fixed uses it as the total; base + overage uses it as the base.</small>
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
          <small>This controls when calculated compensation can move forward for approval or payment.</small>
        </label>
        <label class="field">
          <span class="label">Compensation currency</span>
          <input class="input" name="compensation_currency" maxlength="3" value="<?=$h($form['currency'])?>" required>
        </label>
      </div>
    </fieldset>

    <div class="settings-save-bar" data-settings-save-bar>
      <p class="settings-save-status" aria-live="polite" data-settings-save-status><?=$editing ? 'Editing '.$h($form['name']) : 'Create a reusable Work Activity'?></p>
      <div class="settings-save-actions">
        <a class="btn settings-cancel-button" data-settings-cancel href="/?page=settings&amp;tab=work-types">Cancel</a>
        <button class="btn btn-primary" type="submit"><?=$editing ? 'Save Work Activity' : 'Add Work Activity'?></button>
      </div>
    </div>
  </form>

  <div class="settings-card">
    <div>
      <h3>Existing Work Activities</h3>
      <p class="muted">Deactivating a Work Activity keeps historical time, billing, and compensation records intact.</p>
    </div>
    <div class="pa-table-wrap">
      <table class="pa-table settings-action-table">
        <thead>
          <tr>
            <th>Work Activity</th>
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
            <tr><td colspan="5">No Work Activities yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
