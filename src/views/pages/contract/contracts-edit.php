<?php
// src/views/pages/contracts-edit.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/document_fields.php';
$id = (int)($_GET['id'] ?? 0);
$co = $pdo->prepare('SELECT * FROM contracts WHERE id=?');
$co->execute([$id]);
$contract = $co->fetch(PDO::FETCH_ASSOC);
if(!$contract){ echo '<p>Contract not found</p>'; return; }
$items = $pdo->prepare('SELECT * FROM contract_items WHERE contract_id=?');
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll();

// Fetch existing signatures
$sigStmt = $pdo->prepare('SELECT * FROM contract_signatures WHERE contract_id = ? ORDER BY display_order, id');
$sigStmt->execute([$id]);
$existingSignatures = $sigStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<main class="main-content" role="main">
<section>
  <h2>Edit Contract C-<?php echo htmlspecialchars($contract['doc_number'] ?? $contract['id']); ?><?php if (!empty($contract['project_code'])) echo ' (Project '.htmlspecialchars($contract['project_code']).')'; ?></h2>
  <form id="coEditForm" method="post" action="/?page=contracts-update" style="display:grid;gap:16px;max-width:900px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="id" value="<?php echo (int)$contract['id']; ?>">
    <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">
      <label>
        <div>Client</div>
        <select required name="client_id" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <?php foreach ($clients as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)$contract['client_id']===(int)$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <div>Tax (%)</div>
        <input id="taxPercentCo" type="number" step="0.01" name="tax_percent" value="<?php echo htmlspecialchars($contract['tax_percent'] ?? 0); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Discount Type</div>
        <select id="discountTypeCo" name="discount_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none" <?php echo ($contract['discount_type'] ?? 'none')==='none'?'selected':''; ?>>None</option>
          <option value="percent" <?php echo ($contract['discount_type'] ?? '')==='percent'?'selected':''; ?>>Percent</option>
          <option value="fixed" <?php echo ($contract['discount_type'] ?? '')==='fixed'?'selected':''; ?>>Fixed $</option>
        </select>
      </label>
      <label>
        <div>Discount Value</div>
        <input id="discountValueCo" type="number" step="0.01" name="discount_value" value="<?php echo htmlspecialchars($contract['discount_value'] ?? 0); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Deposit Type</div>
        <select id="depositTypeCo" name="deposit_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none" <?php echo ($contract['deposit_type'] ?? 'none')==='none'?'selected':''; ?>>None</option>
          <option value="percent" <?php echo ($contract['deposit_type'] ?? '')==='percent'?'selected':''; ?>>Percent</option>
          <option value="fixed" <?php echo ($contract['deposit_type'] ?? '')==='fixed'?'selected':''; ?>>Fixed $</option>
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

    <div>
      <div style="font-weight:600;margin-bottom:8px">Items</div>
      <div id="itemsCo" style="display:grid;gap:8px"></div>
      <button type="button" onclick="addItemCo()" style="margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">+ Add Item</button>
    </div>

    <?php 
      $pn=null; $pt=null;
      if (!empty($contract['project_code'])) {
        try {
          $pm=$pdo->prepare('SELECT notes, terms FROM project_meta WHERE project_code=?');
          $pm->execute([$contract['project_code']]);
          $row=$pm->fetch(PDO::FETCH_ASSOC);
          if ($row) { $pn=(string)($row['notes'] ?? ''); $pt=(string)($row['terms'] ?? ''); }
        } catch (Throwable $e) {
          // Older schema may lack 'terms' column; fallback to reading notes only
          try {
            $pm=$pdo->prepare('SELECT notes FROM project_meta WHERE project_code=?');
            $pm->execute([$contract['project_code']]);
            $row=$pm->fetch(PDO::FETCH_ASSOC);
            if ($row) { $pn=(string)($row['notes'] ?? ''); }
          } catch (Throwable $e2) { /* ignore */ }
        }
      }
    ?>
    <?php if (!isset($appConfig['contract_scope_enabled']) || !empty($appConfig['contract_scope_enabled'])): ?>
    <label>
      <div>Scope of Work</div>
      <textarea name="scope" rows="4" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Optional: Describe the scope of work and deliverables for this contract..."><?php echo htmlspecialchars($contract['scope'] ?? ''); ?></textarea>
    </label>
    <?php endif; ?>
    <label>
      <div>Job Notes</div>
      <textarea name="project_notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Shared across related docs"><?php echo htmlspecialchars($pn ?? ''); ?></textarea>
    </label>
    <label>
      <div>Job Terms (override default terms for this job)</div>
      <textarea name="project_terms" rows="6" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="If set, used for all quotes/contracts under this project"><?php echo htmlspecialchars($pt ?? ''); ?></textarea>
    </label>

    <div id="totalsCo" style="margin-top:8px;display:grid;gap:6px;justify-content:end">
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Subtotal</div><div id="subtotalValCo" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Discount</div><div id="discountValCo" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Tax</div><div id="taxValCo" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end;font-weight:700"><div style="min-width:140px;text-align:right">Total</div><div id="totalValCo" style="min-width:120px;text-align:right">$0.00</div></div>
    </div>

    <!-- Signatures Section -->
    <div style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb">
      <h3 style="margin:0 0 8px 0;font-size:15px">Contract Signatures</h3>
      <p style="margin:0 0 12px 0;font-size:13px;color:var(--muted)">Add up to 5 signatures for this contract</p>
      
      <div id="signaturesList" style="display:grid;gap:12px"></div>
      
      <button type="button" onclick="addSignature()" id="addSigBtn"
              style="margin-top:12px;padding:8px 14px;border-radius:6px;border:1px solid #ddd;background:#fff;font-size:13px">
        + Add Signature
      </button>
    </div>

    <div>
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Update Contract</button>
    </div>
  </form>
</section>
<script>
function money(n){return '$'+(Number(n)||0).toFixed(2)}
var itemCounterCo = 0;
function addItemCo(item='', desc='', qty=1, price=0){
  var wrap = document.createElement('div');
  var itemId = 'itemCo_' + (itemCounterCo++);
  var descId = 'descCo_' + itemCounterCo;
  var priceId = 'priceCo_' + itemCounterCo;
  wrap.style.display='grid';wrap.style.gridTemplateColumns='3fr 3fr 1fr 1fr auto';wrap.style.gap='8px';
  
  var itemInput = document.createElement('input');
  itemInput.id = itemId;
  itemInput.required = true;
  itemInput.placeholder = 'Item name...';
  itemInput.name = 'item[]';
  itemInput.style.cssText = 'padding:10px;border-radius:8px;border:1px solid #ddd';
  itemInput.value = item;
  itemInput.oninput = recalcCo;
  itemInput.setAttribute('data-item-autocomplete', '');
  itemInput.setAttribute('data-description-field', descId);
  itemInput.setAttribute('data-price-field', priceId);
  
  var descTextarea = document.createElement('textarea');
  descTextarea.id = descId;
  descTextarea.placeholder = 'Description (optional)';
  descTextarea.name = 'item_desc[]';
  descTextarea.style.cssText = 'padding:10px;border-radius:8px;border:1px solid #ddd;resize:vertical;min-height:42px';
  descTextarea.value = desc;
  descTextarea.oninput = recalcCo;
  
  var qtyInput = document.createElement('input');
  qtyInput.required = true;
  qtyInput.type = 'number';
  qtyInput.step = '0.01';
  qtyInput.min = '0';
  qtyInput.name = 'item_qty[]';
  qtyInput.style.cssText = 'padding:10px;border-radius:8px;border:1px solid #ddd';
  qtyInput.value = qty;
  qtyInput.oninput = recalcCo;
  
  var priceInput = document.createElement('input');
  priceInput.id = priceId;
  priceInput.required = true;
  priceInput.type = 'number';
  priceInput.step = '0.01';
  priceInput.min = '0';
  priceInput.name = 'item_price[]';
  priceInput.style.cssText = 'padding:10px;border-radius:8px;border:1px solid #ddd';
  priceInput.value = price;
  priceInput.oninput = recalcCo;
  
  var removeBtn = document.createElement('button');
  removeBtn.type = 'button';
  removeBtn.textContent = 'Remove';
  removeBtn.style.cssText = 'border:0;background:#fee2e2;color:#991b1b;border-radius:8px;padding:8px 10px';
  removeBtn.onclick = function(){ wrap.remove(); recalcCo(); };
  
  wrap.appendChild(itemInput);
  wrap.appendChild(descTextarea);
  wrap.appendChild(qtyInput);
  wrap.appendChild(priceInput);
  wrap.appendChild(removeBtn);
  document.getElementById('itemsCo').appendChild(wrap);
  
  // Initialize autocomplete for the new item input
  if (window.ItemAutocomplete) {
    new ItemAutocomplete(itemInput, {
      descriptionField: descTextarea,
      priceField: priceInput
    });
  }
  
  recalcCo();
}
function recalcCo(){
  var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e=>parseFloat(e.value)||0);
  var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e=>parseFloat(e.value)||0);
  var subtotal = 0; for (var i=0;i<qtys.length;i++){ subtotal += qtys[i]*prices[i]; }
  var dtype = document.getElementById('discountTypeCo').value;
  var dval = parseFloat(document.getElementById('discountValueCo').value)||0;
  var taxp = parseFloat(document.getElementById('taxPercentCo').value)||0;
  var discount = 0; if (dtype==='percent'){ discount = Math.max(0, Math.min(100,dval))*subtotal/100; } else if (dtype==='fixed'){ discount = Math.max(0,dval); }
  var taxable = Math.max(0, subtotal - discount);
  var tax = Math.max(0, taxp)*taxable/100;
  var total = Math.max(0, taxable + tax);
  document.getElementById('subtotalValCo').textContent = money(subtotal);
  document.getElementById('discountValCo').textContent = money(discount);
  document.getElementById('taxValCo').textContent = money(tax);
  document.getElementById('totalValCo').textContent = money(total);
}
['discountTypeCo','discountValueCo','taxPercentCo'].forEach(id=>document.getElementById(id).addEventListener('input', recalcCo));
// Load existing items
<?php foreach ($items as $it): ?>
addItemCo(<?php echo json_encode($it['item'] ?? ''); ?>, <?php echo json_encode($it['description'] ?? ''); ?>, <?php echo json_encode((float)$it['quantity']); ?>, <?php echo json_encode((float)$it['unit_price']); ?>);
<?php endforeach; ?>

// Signature Management
let signatureCount = 0;
const MAX_SIGNATURES = 5;

function addSignature(title = 'Client Signature', isRequired = true) {
  if (signatureCount >= MAX_SIGNATURES) {
    alert('Maximum of ' + MAX_SIGNATURES + ' signatures allowed');
    return;
  }
  
  signatureCount++;
  const sigId = 'sig_' + Date.now() + '_' + signatureCount;
  
  const wrap = document.createElement('div');
  wrap.className = 'signature-item';
  wrap.dataset.sigId = sigId;
  wrap.style.cssText = 'display:grid;grid-template-columns:1fr auto auto;gap:12px;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff';
  
  wrap.innerHTML = `
    <div style="display:grid;gap:8px">
      <input type="text" name="signature_titles[]" value="${title}" required placeholder="Signature Title (e.g., Project Manager, Owner)"
             style="padding:8px;border-radius:6px;border:1px solid #ddd;font-weight:600">
      <div style="font-size:12px;color:var(--muted)">Order: ${signatureCount}</div>
    </div>
    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
      <input type="checkbox" name="signature_required[]" value="${sigId}" ${isRequired ? 'checked' : ''}>
      Required
    </label>
    <button type="button" onclick="removeSignature('${sigId}')" ${signatureCount === 1 ? 'disabled style="opacity:0.5;cursor:not-allowed"' : ''}
            style="padding:6px 12px;border-radius:6px;border:1px solid #fca5a5;background:#fee2e2;color:#991b1b;font-size:13px">
      Remove
    </button>
    <input type="hidden" name="signature_orders[]" value="${signatureCount}">
  `;
  
  document.getElementById('signaturesList').appendChild(wrap);
  updateSignatureButtons();
}

function removeSignature(sigId) {
  const item = document.querySelector(`[data-sig-id="${sigId}"]`);
  if (item) {
    item.remove();
    signatureCount--;
    
    // Update order numbers
    const items = document.querySelectorAll('.signature-item');
    items.forEach((el, idx) => {
      const orderDiv = el.querySelector('[style*="Order:"]');
      if (orderDiv) orderDiv.textContent = 'Order: ' + (idx + 1);
      const orderInput = el.querySelector('input[name="signature_orders[]"]');
      if (orderInput) orderInput.value = idx + 1;
    });
    
    updateSignatureButtons();
  }
}

function updateSignatureButtons() {
  const items = document.querySelectorAll('.signature-item');
  
  // Enable/disable add button
  if (signatureCount >= MAX_SIGNATURES) {
    document.getElementById('addSigBtn').disabled = true;
    document.getElementById('addSigBtn').style.opacity = '0.5';
    document.getElementById('addSigBtn').style.cursor = 'not-allowed';
  } else {
    document.getElementById('addSigBtn').disabled = false;
    document.getElementById('addSigBtn').style.opacity = '1';
    document.getElementById('addSigBtn').style.cursor = 'pointer';
  }
  
  // Disable remove button on first signature if it's the only one
  items.forEach((el, idx) => {
    const removeBtn = el.querySelector('button[onclick^="removeSignature"]');
    if (removeBtn) {
      if (signatureCount === 1) {
        removeBtn.disabled = true;
        removeBtn.style.opacity = '0.5';
        removeBtn.style.cursor = 'not-allowed';
      } else {
        removeBtn.disabled = false;
        removeBtn.style.opacity = '1';
        removeBtn.style.cursor = 'pointer';
      }
    }
  });
}

// Load existing signatures
<?php if (!empty($existingSignatures)): ?>
<?php foreach ($existingSignatures as $sig): ?>
addSignature(<?php echo json_encode($sig['signer_title']); ?>, <?php echo $sig['is_required'] ? 'true' : 'false'; ?>);
<?php endforeach; ?>
<?php else: ?>
// Add default signature if none exist
addSignature();
<?php endif; ?>
</script>
</main>
