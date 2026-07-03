<?php
// src/views/pages/contracts-edit.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/document_fields.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/project_selection.php';
$id = (int)($_GET['id'] ?? 0);
require_record_ownership($pdo, 'contracts', $id);
$co = $pdo->prepare('SELECT * FROM contracts WHERE id=?');
$co->execute([$id]);
$contract = $co->fetch(PDO::FETCH_ASSOC);
if (!$contract) {
  echo '<p>Contract not found</p>';
  return;
}
$items = $pdo->prepare('SELECT * FROM contract_items WHERE contract_id=?');
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll();
$projectOptions = [];
if (!empty($contract['client_id'])) {
  try {
    [$projectWhere, $projectParams] = pa_active_project_filter_for_client($pdo, (int)$contract['client_id']);
    if (function_exists('scope_clause')) {
      [$scopeWhere, $scopeParams] = scope_clause($pdo, 'p', (int)($_SESSION['user']['id'] ?? 0));
      if ($scopeWhere !== '') {
        $projectWhere[] = trim($scopeWhere);
        $projectParams = array_merge($projectParams, $scopeParams);
      }
    }
    $projectStmt = $pdo->prepare('
      SELECT p.id, p.name, p.status, o.name AS organization_name
      FROM projects p
      LEFT JOIN organizations o ON o.id = p.organization_id
      WHERE ' . implode(' AND ', $projectWhere) . '
      ORDER BY p.name
    ');
    $projectStmt->execute($projectParams);
    $projectOptions = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $projectOptions = [];
  }
}

// Fetch existing signatures
$existingSignatures = [];
try {
  $sigStmt = $pdo->prepare('SELECT * FROM contract_signatures WHERE contract_id = ? ORDER BY display_order, id');
  $sigStmt->execute([$id]);
  $existingSignatures = $sigStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  try {
    $sigStmt = $pdo->prepare('SELECT * FROM contract_signatures WHERE contract_id = ? ORDER BY id');
    $sigStmt->execute([$id]);
    $existingSignatures = $sigStmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $ignored) {
    $existingSignatures = [];
  }
}
?>
<section>
  <h2>Edit Contract C-<?php echo htmlspecialchars($contract['doc_number'] ?? $contract['id']); ?><?php if (!empty($contract['project_code'])) echo ' (Job ' . htmlspecialchars($contract['project_code']) . ')'; ?></h2>
  <form id="coEditForm" method="post" action="/?page=contracts-update" style="display:grid;gap:16px;max-width:900px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="id" value="<?php echo (int)$contract['id']; ?>">
    <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">
      <label>
        <div>Client</div>
        <select required name="client_id" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <?php foreach ($clients as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)$contract['client_id'] === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <div>Tax (%)</div>
        <input id="taxPercentCo" type="number" step="0.01" name="tax_percent" value="<?php echo htmlspecialchars($contract['tax_percent'] ?? 0); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Project</div>
        <select name="project_id" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="">No project</option>
          <?php foreach ($projectOptions as $projectOption): ?>
            <option value="<?php echo (int)$projectOption['id']; ?>" <?php echo (int)($contract['project_id'] ?? 0) === (int)$projectOption['id'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars((string)$projectOption['name']); ?>
              <?php if (!empty($projectOption['organization_name'])): ?>
                (<?php echo htmlspecialchars((string)$projectOption['organization_name']); ?>)
              <?php endif; ?>
              - <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$projectOption['status']))); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <div>Discount Type</div>
        <select id="discountTypeCo" name="discount_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none" <?php echo ($contract['discount_type'] ?? 'none') === 'none' ? 'selected' : ''; ?>>None</option>
          <option value="percent" <?php echo ($contract['discount_type'] ?? '') === 'percent' ? 'selected' : ''; ?>>Percent</option>
          <option value="fixed" <?php echo ($contract['discount_type'] ?? '') === 'fixed' ? 'selected' : ''; ?>>Fixed $</option>
        </select>
      </label>
      <label>
        <div>Discount Value</div>
        <input id="discountValueCo" type="number" step="0.01" name="discount_value" value="<?php echo htmlspecialchars($contract['discount_value'] ?? 0); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Deposit Type</div>
        <select id="depositTypeCo" name="deposit_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none" <?php echo ($contract['deposit_type'] ?? 'none') === 'none' ? 'selected' : ''; ?>>None</option>
          <option value="percent" <?php echo ($contract['deposit_type'] ?? '') === 'percent' ? 'selected' : ''; ?>>Percent</option>
          <option value="fixed" <?php echo ($contract['deposit_type'] ?? '') === 'fixed' ? 'selected' : ''; ?>>Fixed $</option>
        </select>
      </label>
      <label>
        <div>Deposit Amount</div>
        <input id="depositValueCo" type="number" step="0.01" name="deposit_amount" value="<?php echo htmlspecialchars($contract['deposit_amount'] ?? 0); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Deposit Paid</div>
        <input id="depositPaidCo" type="number" step="0.01" name="deposit_paid" value="<?php echo htmlspecialchars($contract['deposit_paid'] ?? 0); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Fulfillment Date</div>
        <input type="date" name="fulfillment_date" value="<?php echo htmlspecialchars($contract['fulfillment_date'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </div>

    <?php
    // Determine document type and render custom fields for contracts (use 'regular' type)
    $documentType = 'regular';

    // Get existing custom field values
    $existingCustomFields = !empty($contract['custom_fields']) ? json_decode($contract['custom_fields'], true) : [];
    if (!is_array($existingCustomFields)) $existingCustomFields = [];

    // Fetch non-builtin custom fields for this document type
    $customFieldsStmt = $pdo->prepare('
        SELECT * FROM document_custom_fields 
        WHERE document_type = ? AND is_enabled = 1 AND is_builtin = 0
        ORDER BY display_order, id
    ');
    $customFieldsStmt->execute([$documentType]);
    $customFields = $customFieldsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($customFields)):
    ?>
      <div style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb">
        <div style="font-weight:600;margin-bottom:12px;color:#374151">Custom Fields</div>
        <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr))">
          <?php foreach ($customFields as $field):
            $fieldKey = $field['field_key'];
            $fieldValue = $existingCustomFields[$fieldKey] ?? '';
          ?>
            <label>
              <div><?php echo htmlspecialchars($field['field_label']); ?><?php if ($field['is_required']): ?> <span style="color:#dc2626">*</span><?php endif; ?></div>
              <?php if ($field['field_type'] === 'date'): ?>
                <input type="date" name="custom_field_<?php echo htmlspecialchars($fieldKey); ?>" value="<?php echo htmlspecialchars($fieldValue); ?>" <?php if ($field['is_required']) echo 'required'; ?> style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              <?php elseif ($field['field_type'] === 'number'): ?>
                <input type="number" step="0.01" name="custom_field_<?php echo htmlspecialchars($fieldKey); ?>" value="<?php echo htmlspecialchars($fieldValue); ?>" <?php if ($field['is_required']) echo 'required'; ?> style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              <?php elseif ($field['field_type'] === 'textarea'): ?>
                <textarea name="custom_field_<?php echo htmlspecialchars($fieldKey); ?>" rows="3" <?php if ($field['is_required']) echo 'required'; ?> style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"><?php echo htmlspecialchars($fieldValue); ?></textarea>
              <?php elseif ($field['field_type'] === 'select'): ?>
                <?php $options = json_decode($field['field_options'] ?? '[]', true); ?>
                <select name="custom_field_<?php echo htmlspecialchars($fieldKey); ?>" <?php if ($field['is_required']) echo 'required'; ?> style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
                  <option value="">-- Select --</option>
                  <?php foreach ($options as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $fieldValue === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input type="text" name="custom_field_<?php echo htmlspecialchars($fieldKey); ?>" value="<?php echo htmlspecialchars($fieldValue); ?>" <?php if ($field['is_required']) echo 'required'; ?> style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div style="margin:12px 0;padding:12px;border:1px solid #dbeafe;border-radius:8px;background:#eff6ff">
      <div style="font-weight:600;margin-bottom:8px">Billing Mode</div>
      <label style="display:flex;align-items:start;gap:8px;cursor:pointer">
        <input type="checkbox" name="billing_mode" value="hourly" <?php echo ($contract['billing_mode'] ?? 'fixed') === 'hourly' ? 'checked' : ''; ?> style="margin-top:3px">
        <div>
          <div style="font-weight:600;color:#1f2937">Hourly billing</div>
          <div style="font-size:13px;color:#4b5563">Use line items as estimated hours and hourly rates.</div>
        </div>
      </label>
    </div>

    <div>
      <div style="font-weight:600;margin-bottom:8px">Items / Rates</div>

      <div id="itemsCo" style="display:grid;gap:8px"></div>
      <button id="addItemBtn" type="button" style="margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">+ Add Item</button>
    </div>

    <?php
    $pn = null;
    $pt = null;
    if (!empty($contract['project_code'])) {
      try {
        $pm = $pdo->prepare('SELECT notes, terms FROM project_meta WHERE project_code=?');
        $pm->execute([$contract['project_code']]);
        $row = $pm->fetch(PDO::FETCH_ASSOC);
        if ($row) {
          $pn = (string)($row['notes'] ?? '');
          $pt = (string)($row['terms'] ?? '');
        }
      } catch (Throwable $e) {
        // Older schema may lack 'terms' column; fallback to reading notes only
        try {
          $pm = $pdo->prepare('SELECT notes FROM project_meta WHERE project_code=?');
          $pm->execute([$contract['project_code']]);
          $row = $pm->fetch(PDO::FETCH_ASSOC);
          if ($row) {
            $pn = (string)($row['notes'] ?? '');
          }
        } catch (Throwable $e2) { /* ignore */
        }
      }
    }
    ?>
    <!-- Job Notes (shared across related docs) -->
    <label>
      <div>Job Notes</div>
      <textarea name="project_notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Shared across related docs"><?php echo htmlspecialchars($pn ?? ''); ?></textarea>
    </label>
    <label>
      <div>Job Terms (override default terms for this job)</div>
      <textarea name="project_terms" rows="6" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="If set, used for all quotes/contracts under this project"><?php echo htmlspecialchars($pt ?? ''); ?></textarea>
    </label>

    <!-- Service Description -->
    <label>
      <div>Service Description</div>
      <textarea name="scope" rows="4" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="e.g. Website hosting, Google Ads management"><?php echo htmlspecialchars($contract['scope'] ?? ''); ?></textarea>
    </label>

    <div id="totalsCo" style="margin-top:8px;display:grid;gap:6px;justify-content:end">
      <div style="display:flex;gap:16px;justify-content:flex-end">
        <div style="min-width:140px;text-align:right;color:var(--muted)">Subtotal</div>
        <div id="subtotalValCo" style="min-width:120px;text-align:right">$0.00</div>
      </div>
      <div style="display:flex;gap:16px;justify-content:flex-end">
        <div style="min-width:140px;text-align:right;color:var(--muted)">Discount</div>
        <div id="discountValCo" style="min-width:120px;text-align:right">$0.00</div>
      </div>
      <div style="display:flex;gap:16px;justify-content:flex-end">
        <div style="min-width:140px;text-align:right;color:var(--muted)">Tax</div>
        <div id="taxValCo" style="min-width:120px;text-align:right">$0.00</div>
      </div>
      <div style="display:flex;gap:16px;justify-content:flex-end;font-weight:700">
        <div style="min-width:140px;text-align:right">Total</div>
        <div id="totalValCo" style="min-width:120px;text-align:right">$0.00</div>
      </div>
    </div>

    <div>
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Update Contract</button>
    </div>
  </form>
</section>

<div id="contract-items-data" type="application/json" style="display:none">
  <?php echo json_encode($items) ?>
</div>

<script src="/assets/js/contracts-edit-logic.js" defer></script>
