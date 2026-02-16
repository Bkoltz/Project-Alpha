<?php
// src/views/pages/invoices-edit.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/document_fields.php';
$id = (int)($_GET['id'] ?? 0);
$iv = $pdo->prepare('SELECT * FROM invoices WHERE id=?');
$iv->execute([$id]);
$inv = $iv->fetch(PDO::FETCH_ASSOC);
if(!$inv){ echo '<p>Invoice not found</p>'; return; }
$items = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id=?');
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll();
$clientName = '';
foreach ($clients as $c) { if ((int)$c['id'] === (int)$inv['client_id']) { $clientName = $c['name']; break; } }
?>
<section>
  <h2>Edit Invoice I-<?php echo htmlspecialchars($inv['doc_number'] ?? $inv['id']); ?><?php if (!empty($inv['project_code'])) echo ' (Job '.htmlspecialchars($inv['project_code']).')'; ?></h2>
  <form id="invEditForm" method="post" action="/?page=invoices-update" style="display:grid;gap:16px;max-width:900px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="id" value="<?php echo (int)$inv['id']; ?>">
    <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr 1fr">
      <label style="position:relative">
        <div>Client</div>
        <input id="clientInputInv" type="text" value="<?php echo htmlspecialchars($clientName); ?>" placeholder="Type client name..." autocomplete="off" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <input id="clientIdInv" type="hidden" name="client_id" value="<?php echo (int)$inv['client_id']; ?>">
        <div id="clientSuggestInv" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
      </label>
      <label>
        <div>Due Date</div>
        <input type="date" name="due_date" value="<?php echo htmlspecialchars($inv['due_date'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Tax (%)</div>
        <input id="taxPercentInv" type="number" step="0.01" name="tax_percent" value="<?php echo htmlspecialchars($inv['tax_percent']); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Discount Type</div>
        <select id="discountTypeInv" name="discount_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none" <?php echo $inv['discount_type']==='none'?'selected':''; ?>>None</option>
          <option value="percent" <?php echo $inv['discount_type']==='percent'?'selected':''; ?>>Percent</option>
          <option value="fixed" <?php echo $inv['discount_type']==='fixed'?'selected':''; ?>>Fixed $</option>
        </select>
      </label>
      <label>
        <div>Discount Value</div>
        <input id="discountValueInv" type="number" step="0.01" name="discount_value" value="<?php echo htmlspecialchars($inv['discount_value']); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Fulfillment Date</div>
        <input type="date" name="fulfillment_date" value="<?php echo htmlspecialchars($inv['fulfillment_date'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </div>

    <?php
    // Render custom fields for invoices (use 'regular' type)
    $documentType = 'regular';
    
    // Get existing custom field values
    $existingCustomFields = !empty($inv['custom_fields']) ? json_decode($inv['custom_fields'], true) : [];
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

    <div>
      <div style="font-weight:600;margin-bottom:8px">Items (from contract - read only)</div>
      <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px">
        <?php 
          $contractItems = [];
          $extraCharges = [];
          foreach ($items as $it) {
            if ((int)($it['is_extra_charge'] ?? 0) === 1) {
              $extraCharges[] = $it;
            } else {
              $contractItems[] = $it;
            }
          }
        ?>
        <?php if (empty($contractItems)): ?>
          <p style="color:#6b7280;margin:0">No items</p>
        <?php else: ?>
          <table style="width:100%;border-collapse:collapse">
            <thead>
              <tr style="border-bottom:2px solid #e5e7eb">
                <th style="text-align:left;padding:8px;color:#6b7280;font-weight:600">Item</th>
                <th style="text-align:left;padding:8px;color:#6b7280;font-weight:600">Description</th>
                <th style="text-align:right;padding:8px;color:#6b7280;font-weight:600;width:100px">Quantity</th>
                <th style="text-align:right;padding:8px;color:#6b7280;font-weight:600;width:120px">Unit Price</th>
                <th style="text-align:right;padding:8px;color:#6b7280;font-weight:600;width:120px">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($contractItems as $it): ?>
                <tr style="border-bottom:1px solid #e5e7eb">
                  <td style="padding:8px;font-weight:600"><?php echo htmlspecialchars($it['item'] ?? ''); ?></td>
                  <td style="padding:8px;color:#6b7280;font-size:13px"><?php echo htmlspecialchars($it['description'] ?? ''); ?></td>
                  <td style="padding:8px;text-align:right"><?php echo htmlspecialchars(number_format((float)$it['quantity'], 2)); ?></td>
                  <td style="padding:8px;text-align:right">$<?php echo htmlspecialchars(number_format((float)$it['unit_price'], 2)); ?></td>
                  <td style="padding:8px;text-align:right">$<?php echo htmlspecialchars(number_format((float)$it['quantity'] * (float)$it['unit_price'], 2)); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
      <p style="color:#6b7280;font-size:0.875rem;margin-top:8px">To change items, modify the contract and apply a discount if needed.</p>
    </div>

    <!-- Extra Charges Section -->
    <div>
      <div style="font-weight:600;margin-bottom:8px">Extra Charges (editable)</div>
      <div style="background:#fffbeb;border:1px solid #fbbf24;border-radius:8px;padding:12px;margin-bottom:8px">
        <?php if (empty($extraCharges)): ?>
          <p style="color:#92400e;margin:0">No extra charges added yet. Use the form below to add additional line items.</p>
        <?php else: ?>
          <div id="extraChargesContainer" style="display:grid;gap:8px">
            <?php foreach ($extraCharges as $idx => $it): ?>
              <div style="display:grid;grid-template-columns:3fr 3fr 1fr 1fr auto;gap:8px;padding:8px;background:#fff;border-radius:4px;border:1px solid #fcd34d">
                <input type="text" name="extra_item[]" value="<?php echo htmlspecialchars($it['item'] ?? ''); ?>" placeholder="Item name..." style="padding:8px;border-radius:4px;border:1px solid #ddd" oninput="recalcInv()" data-item-autocomplete data-description-field="extra_desc_<?php echo $idx; ?>" data-price-field="extra_price_<?php echo $idx; ?>">
                <textarea id="extra_desc_<?php echo $idx; ?>" name="extra_desc[]" placeholder="Description (optional)" style="padding:8px;border-radius:4px;border:1px solid #ddd;resize:vertical;min-height:34px" oninput="recalcInv()"><?php echo htmlspecialchars($it['description'] ?? ''); ?></textarea>
                <input type="number" step="0.01" min="0" name="extra_qty[]" value="<?php echo htmlspecialchars($it['quantity']); ?>" placeholder="Qty" style="padding:8px;border-radius:4px;border:1px solid #ddd" oninput="recalcInv()">
                <input id="extra_price_<?php echo $idx; ?>" type="number" step="0.01" min="0" name="extra_price[]" value="<?php echo htmlspecialchars($it['unit_price']); ?>" placeholder="Price" style="padding:8px;border-radius:4px;border:1px solid #ddd" oninput="recalcInv()">
                <input type="hidden" name="extra_id[]" value="<?php echo htmlspecialchars($it['id']); ?>">
                <button type="button" onclick="if(confirm('Remove this extra charge?')){this.parentElement.remove();recalcInv()}" style="border:0;background:#fee2e2;color:#991b1b;border-radius:4px;padding:8px 10px;cursor:pointer">Remove</button>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <button id="addExtraChargeBtn" type="button" onclick="addExtraCharge()" style="margin-bottom:12px;padding:8px 12px;border-radius:8px;border:1px solid #fbbf24;background:#fffbeb;color:#92400e;cursor:pointer;font-weight:600">+ Add Extra Charge</button>
    </div>

    <?php $pn=null; if (!empty($inv['project_code'])) { $pm=$pdo->prepare('SELECT notes FROM project_meta WHERE project_code=?'); $pm->execute([$inv['project_code']]); $pn=(string)$pm->fetchColumn(); } ?>
    <label>
      <div>Job Notes</div>
      <textarea name="project_notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Shared across related docs"><?php echo htmlspecialchars($pn ?? ''); ?></textarea>
    </label>

    <?php if ((!isset($appConfig['contract_scope_enabled']) || !empty($appConfig['contract_scope_enabled'])) && !empty($inv['scope'])): ?>
    <div>
      <div style="font-weight:600;margin-bottom:8px">Scope of Project (from contract - read only)</div>
      <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;white-space:pre-wrap;color:#374151"><?php echo htmlspecialchars($inv['scope'] ?? ''); ?></div>
      <p style="color:#6b7280;font-size:0.875rem;margin-top:8px">To change scope, modify the contract.</p>
    </div>
    <?php endif; ?>

    <?php
      // Calculate totals from database items (contract + extra charges)
      $subtotal = 0;
      foreach ($items as $it) {
        $subtotal += (float)$it['quantity'] * (float)$it['unit_price'];
      }
      $dtype = $inv['discount_type'] ?? 'none';
      $dval = (float)($inv['discount_value'] ?? 0);
      $discount = 0;
      if ($dtype === 'percent') {
        $discount = max(0, min(100, $dval)) * $subtotal / 100;
      } elseif ($dtype === 'fixed') {
        $discount = max(0, $dval);
      }
      $taxable = max(0, $subtotal - $discount);
      $taxpct = (float)($inv['tax_percent'] ?? 0);
      $tax = max(0, $taxpct) * $taxable / 100;
      $total = max(0, $taxable + $tax);
    ?>
    <div id="totalsInv" style="margin-top:8px;display:grid;gap:6px;justify-content:end">
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Subtotal</div><div id="subtotalValInv" style="min-width:120px;text-align:right">$<?php echo number_format($subtotal, 2); ?></div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Discount</div><div id="discountValInv" style="min-width:120px;text-align:right">$<?php echo number_format($discount, 2); ?></div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Tax</div><div id="taxValInv" style="min-width:120px;text-align:right">$<?php echo number_format($tax, 2); ?></div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end;font-weight:700"><div style="min-width:140px;text-align:right">Total</div><div id="totalValInv" style="min-width:120px;text-align:right">$<?php echo number_format($total, 2); ?></div></div>
    </div>

    <div>
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Update Invoice</button>
    </div>
  </form>
</section>
