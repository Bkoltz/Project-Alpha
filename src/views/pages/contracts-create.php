<?php
// src/views/pages/contracts-create.php
require_once __DIR__ . '/../../config/db.php';
?>
<section>
  <h2>Create Contract</h2>
  <form id="coCreateForm" method="post" action="/?page=contracts-create" style="display:grid;gap:16px;max-width:900px">
    <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">
      <label style="position:relative">
        <div>Client</div>
        <input id="clientInputCo" type="text" placeholder="Type client name..." autocomplete="off" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
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

    <div>
      <div style="font-weight:600;margin-bottom:8px">Items</div>
      <div id="itemsCo" style="display:grid;gap:8px"></div>
      <button type="button" onclick="addItemCo()" style="margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">+ Add Item</button>
    </div>

    <div id="totalsCo" style="margin-top:8px;display:grid;gap:6px;justify-content:end">
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Subtotal</div><div id="subtotalValCo" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Discount</div><div id="discountValCo" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Tax</div><div id="taxValCo" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end;font-weight:700"><div style="min-width:140px;text-align:right">Total</div><div id="totalValCo" style="min-width:120px;text-align:right">$0.00</div></div>
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
document.getElementById('coCreateForm').addEventListener('submit', function(e){ if(!cid.value){ e.preventDefault(); alert('Please select a client from suggestions.'); } });
</script>
