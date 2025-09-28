<?php
// src/views/pages/invoices-edit.php
require_once __DIR__ . '/../../config/db.php';
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
  <h2>Edit Invoice #<?php echo (int)$inv['id']; ?></h2>
  <form id="invEditForm" method="post" action="/?page=invoices-update" style="display:grid;gap:16px;max-width:900px">
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
    </div>

    <div>
      <div style="font-weight:600;margin-bottom:8px">Items</div>
      <div id="itemsInv" style="display:grid;gap:8px"></div>
      <button type="button" onclick="addItemInv()" style="margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">+ Add Item</button>
    </div>

    <div id="totalsInv" style="margin-top:8px;display:grid;gap:6px;justify-content:end">
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Subtotal</div><div id="subtotalValInv" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Discount</div><div id="discountValInv" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Tax</div><div id="taxValInv" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end;font-weight:700"><div style="min-width:140px;text-align:right">Total</div><div id="totalValInv" style="min-width:120px;text-align:right">$0.00</div></div>
    </div>

    <div>
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Update Invoice</button>
    </div>
  </form>
</section>
<script>
function money(n){return '$'+(Number(n)||0).toFixed(2)}
function addItemInv(desc='', qty=1, price=0){
  var wrap = document.createElement('div');
  wrap.style.display='grid';wrap.style.gridTemplateColumns='2fr 1fr 1fr auto';wrap.style.gap='8px';
  wrap.innerHTML = `
    <input required placeholder="Description" name="item_desc[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${desc}" oninput="recalcInv()">
    <input required type="number" step="0.01" min="0" name="item_qty[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${qty}" oninput="recalcInv()">
    <input required type="number" step="0.01" min="0" name="item_price[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${price}" oninput="recalcInv()">
    <button type="button" onclick="this.parentElement.remove();recalcInv()" style="border:0;background:#fee2e2;color:#991b1b;border-radius:8px;padding:8px 10px">Remove</button>
  `;
  document.getElementById('itemsInv').appendChild(wrap);
  recalcInv();
}
function recalcInv(){
  var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e=>parseFloat(e.value)||0);
  var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e=>parseFloat(e.value)||0);
  var subtotal = 0; for (var i=0;i<qtys.length;i++){ subtotal += qtys[i]*prices[i]; }
  var dtype = document.getElementById('discountTypeInv').value;
  var dval = parseFloat(document.getElementById('discountValueInv').value)||0;
  var taxp = parseFloat(document.getElementById('taxPercentInv').value)||0;
  var discount = 0; if (dtype==='percent'){ discount = Math.max(0, Math.min(100,dval))*subtotal/100; } else if (dtype==='fixed'){ discount = Math.max(0,dval); }
  var taxable = Math.max(0, subtotal - discount);
  var tax = Math.max(0, taxp)*taxable/100;
  var total = Math.max(0, taxable + tax);
  document.getElementById('subtotalValInv').textContent = money(subtotal);
  document.getElementById('discountValInv').textContent = money(discount);
  document.getElementById('taxValInv').textContent = money(tax);
  document.getElementById('totalValInv').textContent = money(total);
}
['discountTypeInv','discountValueInv','taxPercentInv'].forEach(id=>document.getElementById(id).addEventListener('input', recalcInv));
<?php foreach ($items as $it): ?>
addItemInv(<?php echo json_encode($it['description']); ?>, <?php echo json_encode((float)$it['quantity']); ?>, <?php echo json_encode((float)$it['unit_price']); ?>);
<?php endforeach; ?>

// Client typeahead
var ciI = document.getElementById('clientInputInv');
var cidI = document.getElementById('clientIdInv');
var sugI = document.getElementById('clientSuggestInv');
ciI.addEventListener('input', function(){
  cidI.value='';
  var t = this.value.trim();
  if(!t){sugI.style.display='none';sugI.innerHTML='';return;}
  fetch('/?page=clients-search&term='+encodeURIComponent(t))
    .then(r=>r.json())
    .then(list=>{
      if(!Array.isArray(list)||list.length===0){sugI.style.display='none';sugI.innerHTML='';return;}
      sugI.innerHTML = list.map(x=>`<div data-id="${x.id}" data-name="${x.name}" style=\"padding:8px 10px;cursor:pointer\">${x.name}</div>`).join('');
      Array.from(sugI.children).forEach(el=>{
        el.addEventListener('click', function(){
          ciI.value = this.dataset.name; cidI.value = this.dataset.id; sugI.style.display='none';
        });
      });
      sugI.style.display='block';
    }).catch(()=>{sugI.style.display='none'});
});
document.addEventListener('click', function(e){ if(!sugI.contains(e.target) && e.target!==ciI){ sugI.style.display='none'; } });
document.getElementById('invEditForm').addEventListener('submit', function(e){ if(!cidI.value){ e.preventDefault(); alert('Please select a client from suggestions.'); } });
</script>
