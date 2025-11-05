<?php
// src/views/pages/contracts-create.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
$csrf = csrf_sf_token('contracts-create');
?>
<section>
  <h2>Create Contract</h2>
  <form id="coCreateForm" method="post" style="display:grid;gap:16px;max-width:900px">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrf); ?>">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">
      <label style="position:relative">
        <div>Client</div>
<input id="clientInputCo" name="client" type="text" placeholder="Type client name..." autocomplete="off" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <input id="clientIdCo" type="hidden" name="client_id">
        <div id="clientSuggestCo" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
      </label>
      <label>
        <div>Tax (%)</div>
        <input id="taxPercentCo" type="number" step="0.01" name="tax_percent" value="0" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Discount Type</div>
        <select id="discountTypeCo" name="discount_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none">None</option>
          <option value="percent">Percent</option>
          <option value="fixed">Fixed $</option>
        </select>
      </label>
      <label>
        <div>Discount Value</div>
        <input id="discountValueCo" type="number" step="0.01" name="discount_value" value="0" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </div>

    <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr 1fr">
      <label>
        <div>Deposit Required</div>
        <select id="depositTypeCo" name="deposit_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none">None</option>
          <option value="percent">Percent</option>
          <option value="fixed">Fixed $</option>
        </select>
      </label>
      <label>
        <div>Deposit Value</div>
        <input id="depositValueCo" type="number" step="0.01" name="deposit_value" value="0" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Fulfillment Date</div>
        <input type="date" name="fulfillment_date" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </div>

    <div style="display:flex;align-items:center;gap:12px;margin:12px 0">
      <input type="checkbox" id="isLongTermCo" name="is_long_term" value="1" onchange="toggleLongTermFields()">
      <label for="isLongTermCo" style="margin:0;font-weight:600">Long-term Contract (Recurring Billing)</label>
    </div>

    <div id="longTermFields" style="display:none;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb">
      <h3 style="margin:0 0 12px 0;color:#374151">Recurring Billing Settings</h3>
      
      <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">
        <label>
          <div>Start Date *</div>
          <input id="startDateField" type="date" name="start_date" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>Contract Duration *</div>
          <select id="endDateType" name="end_date_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" onchange="toggleEndDate()">
            <option value="on_termination">Ongoing (Until Terminated)</option>
            <option value="specific_date">Fixed End Date</option>
          </select>
        </label>
      </div>
      
      <div id="endDateField" style="display:none;margin-top:12px">
        <label>
          <div>End Date *</div>
          <input type="date" name="end_date" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
      </div>

      <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr;margin-top:12px">
        <label>
          <div>Bill Every *</div>
          <select id="billingIntervalCount" name="billing_interval_count" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            <option value="1">1</option>
            <option value="2">2</option>
            <option value="3">3</option>
            <option value="6">6</option>
            <option value="12">12</option>
          </select>
        </label>
        <label>
          <div>Period *</div>
          <select id="billingIntervalUnit" name="billing_interval_unit" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            <option value="day">Day(s)</option>
            <option value="week">Week(s)</option>
            <option value="month" selected>Month(s)</option>
            <option value="year">Year(s)</option>
          </select>
        </label>
      </div>

      <div style="margin-top:16px;padding:12px;background:#fef3c7;border-radius:8px;border:1px solid #fbbf24">
        <div style="font-weight:600;margin-bottom:8px;color:#92400e">How should the client be billed?</div>
        <label style="display:flex;align-items:start;gap:8px;margin-bottom:8px;cursor:pointer">
          <input type="radio" id="recurringPerInvoice" name="pricing_type" value="per_invoice" checked onchange="togglePricingFields()" style="margin-top:3px">
          <div>
            <div style="font-weight:600;color:#374151">Recurring Amount</div>
            <div style="font-size:13px;color:#6b7280">Client pays the same amount on each invoice (e.g., $20/month)</div>
          </div>
        </label>
        <label id="fixedTotalOption" style="display:flex;align-items:start;gap:8px;cursor:pointer">
          <input type="radio" id="recurringFixedTotal" name="pricing_type" value="fixed_total" onchange="togglePricingFields()" style="margin-top:3px">
          <div>
            <div style="font-weight:600;color:#374151">Fixed Total (Billed Over Time)</div>
            <div style="font-size:13px;color:#6b7280">Total contract amount is divided across invoices until paid in full</div>
          </div>
        </label>
      </div>

      <div id="perInvoiceField" style="margin-top:12px">
        <label>
          <div>Amount Per Invoice * <span style="font-size:13px;color:#6b7280;font-weight:normal">(before tax & discount)</span></div>
          <input id="pricePerInvoiceInput" type="number" step="0.01" name="price_per_invoice" placeholder="20.00" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" oninput="recalcCo()">
        </label>
      </div>

      <div id="discountWarning" style="display:none;margin-top:12px;padding:10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;font-size:13px">
        <strong>Note:</strong> For ongoing contracts, discounts apply to each invoice, not the total contract value.
      </div>
    </div>

    <div>
      <div style="font-weight:600;margin-bottom:8px">Items</div>
      <div id="itemsCo" style="display:grid;gap:8px"></div>
      <button type="button" onclick="addItemCo()" style="margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">+ Add Item</button>
    </div>

    <label>
      <div>Scope of Work</div>
      <textarea name="scope" rows="4" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Optional: Describe the scope of work and deliverables for this contract..."></textarea>
    </label>

    <label>
      <div>Project Notes (shared across related docs)</div>
      <textarea name="project_notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Notes visible to you (not the client PDF)"></textarea>
    </label>

    <div id="invoiceAmountRow" style="display:none;margin-top:8px;padding:12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div style="font-weight:600;color:#065f46">Amount Per Invoice:</div>
        <div id="invoiceAmountVal" style="font-size:18px;font-weight:700;color:#065f46">$0.00</div>
      </div>
    </div>

    <div id="totalsCo" style="margin-top:8px;display:grid;gap:6px;justify-content:end">
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Subtotal</div><div id="subtotalValCo" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Discount</div><div id="discountValCo" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Tax</div><div id="taxValCo" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end;font-weight:700"><div style="min-width:140px;text-align:right">Total</div><div id="totalValCo" style="min-width:120px;text-align:right">$0.00</div></div>
      <div id="depositRowCo" style="display:none;border-top:1px solid #e5e7eb;padding-top:6px;margin-top:6px">
        <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:#059669;font-weight:700;font-size:15px">Deposit Due</div><div id="depositValCo" style="min-width:120px;text-align:right;color:#059669;font-weight:700;font-size:15px">$0.00</div></div>
      </div>
    </div>

    <div>
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Create Contract</button>
    </div>
  </form>
</section>
<script>
function money(n){return '$'+(Number(n)||0).toFixed(2)}
function addItemCo(desc='', qty=1, price=0){
  var wrap = document.createElement('div');
  wrap.style.display='grid';wrap.style.gridTemplateColumns='2fr 1fr 1fr auto';wrap.style.gap='8px';
  wrap.innerHTML = `
    <input required placeholder="Description" name="item_desc[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${desc}" oninput="recalcCo()">
    <input required type="number" step="0.01" min="0" name="item_qty[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${qty}" oninput="recalcCo()">
    <input required type="number" step="0.01" min="0" name="item_price[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${price}" oninput="recalcCo()">
    <button type="button" onclick="this.parentElement.remove();recalcCo()" style="border:0;background:#fee2e2;color:#991b1b;border-radius:8px;padding:8px 10px">Remove</button>
  `;
  document.getElementById('itemsCo').appendChild(wrap);
  recalcCo();
}
function recalcCo(){
  var isLongTerm = document.getElementById('isLongTermCo').checked;
  var pricingType = isLongTerm ? document.querySelector('input[name="pricing_type"]:checked')?.value : null;
  var isOngoing = isLongTerm && document.getElementById('endDateType').value === 'on_termination';
  
  var subtotal = 0;
  
  // Calculate subtotal based on pricing type
  if (isLongTerm && pricingType === 'per_invoice') {
    // Use price per invoice
    subtotal = parseFloat(document.getElementById('pricePerInvoiceInput').value) || 0;
  } else {
    // Use line items
    var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e=>parseFloat(e.value)||0);
    var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e=>parseFloat(e.value)||0);
    for (var i=0;i<qtys.length;i++){ subtotal += qtys[i]*prices[i]; }
  }
  
  var dtype = document.getElementById('discountTypeCo').value;
  var dval = parseFloat(document.getElementById('discountValueCo').value)||0;
  var taxp = parseFloat(document.getElementById('taxPercentCo').value)||0;
  var discount = 0; 
  if (dtype==='percent'){ discount = Math.max(0, Math.min(100,dval))*subtotal/100; } 
  else if (dtype==='fixed'){ discount = Math.max(0,dval); }
  var taxable = Math.max(0, subtotal - discount);
  var tax = Math.max(0, taxp)*taxable/100;
  var total = Math.max(0, taxable + tax);
  
  // Calculate deposit
  var depType = document.getElementById('depositTypeCo').value;
  var depVal = parseFloat(document.getElementById('depositValueCo').value)||0;
  var deposit = 0;
  if (depType==='percent'){ deposit = Math.max(0, Math.min(100,depVal))*total/100; } 
  else if (depType==='fixed'){ deposit = Math.max(0,depVal); }
  
  document.getElementById('subtotalValCo').textContent = money(subtotal);
  document.getElementById('discountValCo').textContent = money(discount);
  document.getElementById('taxValCo').textContent = money(tax);
  
  // For ongoing contracts, don't show total (unknown)
  if (isOngoing) {
    document.getElementById('totalValCo').parentElement.style.display = 'none';
  } else {
    document.getElementById('totalValCo').parentElement.style.display = 'flex';
    document.getElementById('totalValCo').textContent = money(total);
  }
  
  // Show invoice amount for long-term contracts
  if (isLongTerm) {
    document.getElementById('invoiceAmountRow').style.display = 'block';
    document.getElementById('invoiceAmountVal').textContent = money(total);
  } else {
    document.getElementById('invoiceAmountRow').style.display = 'none';
  }
  
  // Show/hide deposit row
  if (depType !== 'none' && deposit > 0) {
    document.getElementById('depositRowCo').style.display = 'block';
    document.getElementById('depositValCo').textContent = money(deposit);
  } else {
    document.getElementById('depositRowCo').style.display = 'none';
  }
  
  // Update discount warning
  updateDiscountWarning();
}
['discountTypeCo','discountValueCo','taxPercentCo','depositTypeCo','depositValueCo'].forEach(id=>document.getElementById(id).addEventListener('input', recalcCo));
document.getElementById('discountTypeCo').addEventListener('change', updateDiscountWarning);

// Set start date to today when page loads
window.addEventListener('DOMContentLoaded', function() {
  var today = new Date().toISOString().split('T')[0];
  document.getElementById('startDateField').value = today;
});

function toggleLongTermFields() {
  var isChecked = document.getElementById('isLongTermCo').checked;
  document.getElementById('longTermFields').style.display = isChecked ? 'block' : 'none';
  
  if (isChecked) {
    // Show/hide based on pricing type
    togglePricingFields();
    // Show discount warning if ongoing contract
    updateDiscountWarning();
  } else {
    // Regular contract - always show items
    document.getElementById('itemsCo').parentElement.style.display = 'block';
    document.getElementById('invoiceAmountRow').style.display = 'none';
  }
  recalcCo();
}

function toggleEndDate() {
  var type = document.getElementById('endDateType').value;
  var isOngoing = (type === 'on_termination');
  
  document.getElementById('endDateField').style.display = isOngoing ? 'none' : 'block';
  
  // Show/hide fixed total option based on contract duration
  var fixedTotalOption = document.getElementById('fixedTotalOption');
  if (isOngoing) {
    fixedTotalOption.style.display = 'none';
    // Force per_invoice if ongoing
    document.getElementById('recurringPerInvoice').checked = true;
  } else {
    fixedTotalOption.style.display = 'flex';
  }
  
  togglePricingFields();
  updateDiscountWarning();
}

function togglePricingFields() {
  var isLongTerm = document.getElementById('isLongTermCo').checked;
  if (!isLongTerm) return;
  
  var pricingType = document.querySelector('input[name="pricing_type"]:checked').value;
  
  if (pricingType === 'per_invoice') {
    document.getElementById('perInvoiceField').style.display = 'block';
    document.getElementById('itemsCo').parentElement.style.display = 'none';
  } else {
    document.getElementById('perInvoiceField').style.display = 'none';
    document.getElementById('itemsCo').parentElement.style.display = 'block';
  }
  recalcCo();
}

function updateDiscountWarning() {
  var isLongTerm = document.getElementById('isLongTermCo').checked;
  var isOngoing = document.getElementById('endDateType').value === 'on_termination';
  var discountType = document.getElementById('discountTypeCo').value;
  
  var warning = document.getElementById('discountWarning');
  if (isLongTerm && isOngoing && discountType !== 'none') {
    warning.style.display = 'block';
  } else {
    warning.style.display = 'none';
  }
}

addItemCo();

// Client typeahead
var ci = document.getElementById('clientInputCo');
var cid = document.getElementById('clientIdCo');
var sug = document.getElementById('clientSuggestCo');
ci.addEventListener('input', function(){
  cid.value='';
  var t = this.value.trim();
  if(!t){sug.style.display='none';sug.innerHTML='';return;}
  fetch('/?page=clients-search&term='+encodeURIComponent(t))
    .then(r=>r.json())
    .then(list=>{
      if(!Array.isArray(list)||list.length===0){sug.style.display='none';sug.innerHTML='';return;}
sug.innerHTML = list.map(x=>`<div data-id="${x.id}" data-name="${x.name}" style="padding:8px 10px;cursor:pointer">${x.name}</div>`).join('');
      Array.from(sug.children).forEach(el=>{
        el.addEventListener('click', function(){
          ci.value = this.dataset.name; cid.value = this.dataset.id; sug.style.display='none';
        });
      });
      sug.style.display='block';
    }).catch(()=>{sug.style.display='none'});
  });
document.addEventListener('click', function(e){ if(!sug.contains(e.target) && e.target!==ci){ sug.style.display='none'; } });
document.getElementById('coCreateForm').addEventListener('submit', function(e){ 
  if(!cid.value){ 
    e.preventDefault(); 
    alert('Please select a client from suggestions.'); 
    return;
  }
  // Set form action based on contract type
  var isLongTerm = document.getElementById('isLongTermCo').checked;
  this.action = isLongTerm ? '/?page=long-term-contracts-create' : '/?page=contracts-create';
});
</script>
