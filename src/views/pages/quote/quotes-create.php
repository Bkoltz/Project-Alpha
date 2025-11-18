<?php
// src/views/pages/quotes-create.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll();
?>
<section>
  <h2>Create Quote</h2>
  <form id="quoteForm" method="post" action="/?page=quote/quotes-create" style="display:grid;gap:16px;max-width:900px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">
      <label style="grid-column:1/2;position:relative">
        <div>Client</div>
        <input id="clientInput" type="text" placeholder="Type client name..." autocomplete="off" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <input id="clientId" type="hidden" name="client_id">
        <div id="clientSuggest" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
      </label>
      <label style="grid-column:2/3">
        <div>Tax (%)</div>
        <input id="taxPercent" type="number" step="0.01" name="tax_percent" value="0" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Discount Type</div>
        <select id="discountType" name="discount_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none">None</option>
          <option value="percent">Percent</option>
          <option value="fixed">Fixed $</option>
        </select>
      </label>
      <label>
        <div>Discount Value</div>
        <input id="discountValue" type="number" step="0.01" name="discount_value" value="0" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </div>

    <div id="depositFulfillmentRow" style="display:grid;gap:12px;grid-template-columns:1fr 1fr 1fr">
      <label id="depositTypeLabel">
        <div>Deposit Required</div>
        <select id="depositType" name="deposit_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none">None</option>
          <option value="percent">Percent</option>
          <option value="fixed">Fixed $</option>
        </select>
      </label>
      <label id="depositValueLabel">
        <div>Deposit Value</div>
        <input id="depositValue" type="number" step="0.01" name="deposit_value" value="0" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label id="fulfillmentDateLabel">
        <div>Fulfillment Date (Estimated)</div>
        <input type="date" name="fulfillment_date" id="fulfillmentDateInput" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </div>

    <div style="display:flex;align-items:center;gap:12px;margin:12px 0">
      <input type="checkbox" id="isLongTerm" name="is_long_term" value="1" onchange="toggleLongTermFields()">
      <label for="isLongTerm" style="margin:0;font-weight:600">Long-term Service Quote (Recurring Billing)</label>
    </div>

    <div id="longTermFields" style="display:none;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb">
      <h3 style="margin:0 0 12px 0;color:#374151">Recurring Billing Settings</h3>
      
      <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">
        <label>
          <div>Start Date *</div>
          <input id="startDateField" type="date" name="lt_start_date" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>Contract Duration *</div>
          <select id="endDateType" name="lt_end_date_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" onchange="toggleEndDate()">
            <option value="ongoing">Ongoing (Until Terminated)</option>
            <option value="fixed">Fixed End Date</option>
          </select>
        </label>
      </div>
      
      <div id="endDateField" style="display:none;margin-top:12px">
        <label>
          <div>End Date *</div>
          <input type="date" name="lt_end_date" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
      </div>

      <div id="billingIntervalFields" style="display:grid;gap:12px;grid-template-columns:1fr 1fr;margin-top:12px">
        <label>
          <div>Bill Every *</div>
          <select id="billingIntervalCount" name="lt_billing_interval_count" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            <option value="1">1</option>
            <option value="2">2</option>
            <option value="3">3</option>
            <option value="6">6</option>
            <option value="12">12</option>
          </select>
        </label>
        <label>
          <div>Period *</div>
          <select id="billingIntervalUnit" name="lt_billing_interval_unit" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
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
          <input type="radio" id="recurringPerInvoice" name="lt_pricing_type" value="per_invoice" checked onchange="togglePricingFields()" style="margin-top:3px">
          <div>
            <div style="font-weight:600;color:#374151">Recurring Amount</div>
            <div style="font-size:13px;color:#6b7280">Client pays the same amount on each invoice (e.g., $20/month)</div>
          </div>
        </label>
        <label id="fixedTotalOption" style="display:flex;align-items:start;gap:8px;margin-bottom:8px;cursor:pointer">
          <input type="radio" id="recurringFixedTotal" name="lt_pricing_type" value="fixed_total" onchange="togglePricingFields()" style="margin-top:3px">
          <div>
            <div style="font-weight:600;color:#374151">Fixed Total (Billed Over Time)</div>
            <div style="font-size:13px;color:#6b7280">Total quote amount is divided across invoices until paid in full</div>
          </div>
        </label>
        <label style="display:flex;align-items:start;gap:8px;cursor:pointer">
          <input type="radio" id="onDemandQuote" name="lt_pricing_type" value="on_demand" onchange="togglePricingFields()" style="margin-top:3px">
          <div>
            <div style="font-weight:600;color:#374151">On Demand</div>
            <div style="font-size:13px;color:#6b7280">Invoices generated manually on-demand without deposits</div>
          </div>
        </label>
      </div>

      <div id="perInvoiceField" style="margin-top:12px">
        <label>
          <div>Amount Per Invoice * <span style="font-size:13px;color:#6b7280;font-weight:normal">(before tax & discount)</span></div>
          <input id="pricePerInvoiceInput" type="number" step="0.01" name="lt_price_per_invoice" placeholder="20.00" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" oninput="recalc()">
        </label>
      </div>

      <div id="fixedTotalFields" style="display:none;margin-top:12px">
        <label>
          <div>Number of Invoices * <span style="font-size:13px;color:#6b7280;font-weight:normal">(how many invoices to divide the total across)</span></div>
          <input id="invoiceCountInput" type="number" step="1" min="1" name="invoice_count" placeholder="4" value="4" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" oninput="recalc()">
        </label>
        <div id="calculatedPricePerInvoice" style="margin-top:8px;padding:10px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div style="font-weight:600;color:#065f46">Price Per Invoice:</div>
            <div id="calcPriceVal" style="font-size:16px;font-weight:700;color:#065f46">$0.00</div>
          </div>
        </div>
      </div>

      <div id="discountWarning" style="display:none;margin-top:12px;padding:10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;font-size:13px">
        <strong>Note:</strong> For ongoing quotes, discounts apply to each invoice, not the total contract value.
      </div>
    </div>

    <div>
      <div style="font-weight:600;margin-bottom:8px">Items</div>
      <div id="items" style="display:grid;gap:8px"></div>
      <button type="button" onclick="addItem()" style="margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">+ Add Item</button>
    </div>

    <?php if (!isset($appConfig['quote_scope_enabled']) || !empty($appConfig['quote_scope_enabled'])): ?>
    <label>
      <div>Scope of Work</div>
      <textarea name="scope" rows="4" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Optional: Describe the scope of work and deliverables..."></textarea>
    </label>
    <?php endif; ?>

    <label>
      <div>Project Notes (shared across related docs)</div>
      <textarea name="project_notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Notes visible to you (not the client PDF)"
      ></textarea>
    </label>

    <div id="invoiceAmountRow" style="display:none;margin-top:8px;padding:12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div style="font-weight:600;color:#065f46">Amount Per Invoice:</div>
        <div id="invoiceAmountVal" style="font-size:18px;font-weight:700;color:#065f46">$0.00</div>
      </div>
    </div>

    <div id="totals" style="margin-top:8px;display:grid;gap:6px;justify-content:end">
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Subtotal</div><div id="subtotalVal" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Discount</div><div id="discountVal" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Tax</div><div id="taxVal" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end;font-weight:700"><div style="min-width:140px;text-align:right">Total</div><div id="totalVal" style="min-width:120px;text-align:right">$0.00</div></div>
      <div id="depositRow" style="display:none;border-top:1px solid #e5e7eb;padding-top:6px;margin-top:6px">
        <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:#059669;font-weight:700;font-size:15px">Deposit Due</div><div id="depositVal" style="min-width:120px;text-align:right;color:#059669;font-weight:700;font-size:15px">$0.00</div></div>
      </div>
    </div>

    <div>
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Create Quote</button>
    </div>
  </form>
</section>
<script>
function money(n){return '$'+(Number(n)||0).toFixed(2)}
function addItem(desc='', qty=1, price=0){
  var wrap = document.createElement('div');
  wrap.style.display='grid';wrap.style.gridTemplateColumns='2fr 1fr 1fr auto';wrap.style.gap='8px';
  wrap.innerHTML = `
    <input required placeholder="Description" name="item_desc[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${desc}" oninput="recalc()">
    <input required type="number" step="0.01" min="0" name="item_qty[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${qty}" oninput="recalc()">
    <input required type="number" step="0.01" min="0" name="item_price[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${price}" oninput="recalc()">
    <button type="button" onclick="this.parentElement.remove();recalc()" style="border:0;background:#fee2e2;color:#991b1b;border-radius:8px;padding:8px 10px">Remove</button>
  `;
  document.getElementById('items').appendChild(wrap);
  recalc();
}
function recalc(){
  var isLongTerm = document.getElementById('isLongTerm').checked;
  var pricingType = isLongTerm ? document.querySelector('input[name="lt_pricing_type"]:checked')?.value : null;
  var isOngoing = isLongTerm && document.getElementById('endDateType').value === 'ongoing';
  
  var subtotal = 0;
  
  // Calculate subtotal based on pricing type
  if (isLongTerm && (pricingType === 'per_invoice' || pricingType === 'on_demand')) {
    subtotal = parseFloat(document.getElementById('pricePerInvoiceInput').value) || 0;
  } else {
    var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e=>parseFloat(e.value)||0);
    var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e=>parseFloat(e.value)||0);
    for (var i=0;i<qtys.length;i++){ subtotal += qtys[i]*prices[i]; }
  }
  
  // For fixed_total pricing, calculate price per invoice
  if (isLongTerm && pricingType === 'fixed_total') {
    var invoiceCount = parseInt(document.getElementById('invoiceCountInput').value) || 1;
    var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e=>parseFloat(e.value)||0);
    var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e=>parseFloat(e.value)||0);
    subtotal = 0;
    for (var i=0;i<qtys.length;i++){ subtotal += qtys[i]*prices[i]; }
  }
  
  var dtype = document.getElementById('discountType').value;
  var dval = parseFloat(document.getElementById('discountValue').value)||0;
  var taxp = parseFloat(document.getElementById('taxPercent').value)||0;
  var discount = 0; 
  if (dtype==='percent'){ discount = Math.max(0, Math.min(100,dval))*subtotal/100; } 
  else if (dtype==='fixed'){ discount = Math.max(0,dval); }
  var taxable = Math.max(0, subtotal - discount);
  var tax = Math.max(0, taxp)*taxable/100;
  var total = Math.max(0, taxable + tax);
  
  // Calculate deposit
  var depType = document.getElementById('depositType').value;
  var depVal = parseFloat(document.getElementById('depositValue').value)||0;
  var deposit = 0;
  if (depType==='percent'){ deposit = Math.max(0, Math.min(100,depVal))*total/100; } 
  else if (depType==='fixed'){ deposit = Math.max(0,depVal); }
  
  document.getElementById('subtotalVal').textContent = money(subtotal);
  document.getElementById('discountVal').textContent = money(discount);
  document.getElementById('taxVal').textContent = money(tax);
  
  // For ongoing quotes, don't show total (unknown)
  if (isOngoing) {
    document.getElementById('totalVal').parentElement.style.display = 'none';
  } else {
    document.getElementById('totalVal').parentElement.style.display = 'flex';
    document.getElementById('totalVal').textContent = money(total);
  }
  
  // Show calculated price per invoice for fixed_total
  if (isLongTerm && pricingType === 'fixed_total') {
    var invoiceCount = parseInt(document.getElementById('invoiceCountInput').value) || 1;
    var pricePerInv = total / invoiceCount;
    document.getElementById('calcPriceVal').textContent = money(pricePerInv);
    document.getElementById('invoiceAmountRow').style.display = 'none';
  } else if (isLongTerm) {
    document.getElementById('invoiceAmountRow').style.display = 'block';
    document.getElementById('invoiceAmountVal').textContent = money(total);
  } else {
    document.getElementById('invoiceAmountRow').style.display = 'none';
  }
  
  // Show/hide deposit row
  if (depType !== 'none' && deposit > 0) {
    document.getElementById('depositRow').style.display = 'block';
    document.getElementById('depositVal').textContent = money(deposit);
  } else {
    document.getElementById('depositRow').style.display = 'none';
  }
  
  updateDiscountWarning();
}

['discountType','discountValue','taxPercent','depositType','depositValue'].forEach(id=>document.getElementById(id).addEventListener('input', recalc));
document.getElementById('discountType').addEventListener('change', updateDiscountWarning);

// No need for DOMContentLoaded start date setting - now handled in toggleLongTermFields

function toggleLongTermFields() {
  var isChecked = document.getElementById('isLongTerm').checked;
  document.getElementById('longTermFields').style.display = isChecked ? 'block' : 'none';
  
  if (isChecked) {
    // Set start date to today when first enabling LT
    var startField = document.getElementById('startDateField');
    if (!startField.value) {
      startField.value = new Date().toISOString().split('T')[0];
    }
    // Trigger toggleEndDate to set initial state correctly
    toggleEndDate();
    togglePricingFields();
    updateDiscountWarning();
  } else {
    document.getElementById('items').parentElement.style.display = 'block';
    document.getElementById('invoiceAmountRow').style.display = 'none';
    // Show deposit and fulfillment for regular quotes
    document.getElementById('depositTypeLabel').style.display = 'block';
    document.getElementById('depositValueLabel').style.display = 'block';
    document.getElementById('fulfillmentDateLabel').style.display = 'block';
  }
  recalc();
}

function toggleEndDate() {
  var type = document.getElementById('endDateType').value;
  var isOngoing = (type === 'ongoing');
  
  document.getElementById('endDateField').style.display = isOngoing ? 'none' : 'block';
  
  // Hide fulfillment date when ongoing
  var fulfillmentLabel = document.getElementById('fulfillmentDateLabel');
  if (document.getElementById('isLongTerm').checked) {
    fulfillmentLabel.style.display = isOngoing ? 'none' : 'block';
  }
  
  var fixedTotalOption = document.getElementById('fixedTotalOption');
  if (isOngoing) {
    fixedTotalOption.style.display = 'none';
    document.getElementById('recurringPerInvoice').checked = true;
  } else {
    fixedTotalOption.style.display = 'flex';
  }
  
  togglePricingFields();
  updateDiscountWarning();
  recalc();
}

function togglePricingFields() {
  var isLongTerm = document.getElementById('isLongTerm').checked;
  if (!isLongTerm) {
    // Regular quote - show deposit and fulfillment
    document.getElementById('depositTypeLabel').style.display = 'block';
    document.getElementById('depositValueLabel').style.display = 'block';
    document.getElementById('fulfillmentDateLabel').style.display = 'block';
    document.getElementById('billingIntervalFields').style.display = 'grid';
    return;
  }
  
  var pricingType = document.querySelector('input[name="lt_pricing_type"]:checked').value;
  
  if (pricingType === 'per_invoice') {
    // Recurring amount - hide deposit and fulfillment
    document.getElementById('depositTypeLabel').style.display = 'none';
    document.getElementById('depositValueLabel').style.display = 'none';
    document.getElementById('fulfillmentDateLabel').style.display = 'none';
    document.getElementById('perInvoiceField').style.display = 'block';
    document.getElementById('fixedTotalFields').style.display = 'none';
    document.getElementById('items').parentElement.style.display = 'none';
    document.getElementById('billingIntervalFields').style.display = 'grid';
  } else if (pricingType === 'on_demand') {
    // On-demand - show deposits, hide fulfillment and billing interval
    document.getElementById('depositTypeLabel').style.display = 'block';
    document.getElementById('depositValueLabel').style.display = 'block';
    document.getElementById('fulfillmentDateLabel').style.display = 'none';
    document.getElementById('perInvoiceField').style.display = 'block';
    document.getElementById('fixedTotalFields').style.display = 'none';
    document.getElementById('items').parentElement.style.display = 'none';
    document.getElementById('billingIntervalFields').style.display = 'none';
  } else {
    // Fixed total - show deposit and fulfillment
    document.getElementById('depositTypeLabel').style.display = 'block';
    document.getElementById('depositValueLabel').style.display = 'block';
    var isOngoing = document.getElementById('endDateType').value === 'ongoing';
    document.getElementById('fulfillmentDateLabel').style.display = isOngoing ? 'none' : 'block';
    document.getElementById('perInvoiceField').style.display = 'none';
    document.getElementById('fixedTotalFields').style.display = 'block';
    document.getElementById('items').parentElement.style.display = 'block';
    document.getElementById('billingIntervalFields').style.display = 'grid';
  }
  recalc();
}

function updateDiscountWarning() {
  var isLongTerm = document.getElementById('isLongTerm').checked;
  var isOngoing = document.getElementById('endDateType').value === 'ongoing';
  var discountType = document.getElementById('discountType').value;
  
  var warning = document.getElementById('discountWarning');
  if (isLongTerm && isOngoing && discountType !== 'none') {
    warning.style.display = 'block';
  } else {
    warning.style.display = 'none';
  }
}

addItem();

// Client typeahead
var ci = document.getElementById('clientInput');
var cid = document.getElementById('clientId');
var sug = document.getElementById('clientSuggest');
ci.addEventListener('input', function(){
  cid.value='';
  var t = this.value.trim();
  if(!t){sug.style.display='none';sug.innerHTML='';return;}
  fetch('/?page=clients-search&term='+encodeURIComponent(t))
    .then(r=>r.json())
    .then(list=>{
      if(!Array.isArray(list)||list.length===0){sug.style.display='none';sug.innerHTML='';return;}
      sug.innerHTML = list.map(x=>`<div data-id="${x.id}" data-name="${x.name}" style=\"padding:8px 10px;cursor:pointer\">${x.name}</div>`).join('');
      Array.from(sug.children).forEach(el=>{
        el.addEventListener('click', function(){
          ci.value = this.dataset.name; cid.value = this.dataset.id; sug.style.display='none';
        });
      });
      sug.style.display='block';
    }).catch(()=>{sug.style.display='none'});
  });
document.addEventListener('click', function(e){ if(!sug.contains(e.target) && e.target!==ci){ sug.style.display='none'; } });

document.getElementById('quoteForm').addEventListener('submit', function(e){ if(!cid.value){ e.preventDefault(); alert('Please select a client from suggestions.'); } });
</script>
