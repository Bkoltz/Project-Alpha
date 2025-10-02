<?php
// src/views/pages/contracts-edit.php
require_once __DIR__ . '/../../config/db.php';
$id = (int)($_GET['id'] ?? 0);
$co = $pdo->prepare('SELECT * FROM contracts WHERE id=?');
$co->execute([$id]);
$contract = $co->fetch(PDO::FETCH_ASSOC);
if(!$contract){ echo '<p>Contract not found</p>'; return; }
$items = $pdo->prepare('SELECT * FROM contract_items WHERE contract_id=?');
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll();
?>
<section>
  <h2>Edit Contract C-<?php echo htmlspecialchars($contract['doc_number'] ?? $contract['id']); ?><?php if (!empty($contract['project_code'])) echo ' (Project '.htmlspecialchars($contract['project_code']).')'; ?></h2>
  <form id="coEditForm" method="post" action="/?page=contracts-update" style="display:grid;gap:16px;max-width:900px">
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
    </div>

    <div>
      <div style="font-weight:600;margin-bottom:8px">Items</div>
      <div id="itemsCo" style="display:grid;gap:8px"></div>
      <button type="button" onclick="addItemCo()" style="margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">+ Add Item</button>
    </div>

    <?php $pn=null; if (!empty($contract['project_code'])) { $pm=$pdo->prepare('SELECT notes FROM project_meta WHERE project_code=?'); $pm->execute([$contract['project_code']]); $pn=(string)$pm->fetchColumn(); } ?>
    <label>
      <div>Project Notes</div>
      <textarea name="project_notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Shared across related docs"><?php echo htmlspecialchars($pn ?? ''); ?></textarea>
    </label>

    <div id="totalsCo" style="margin-top:8px;display:grid;gap:6px;justify-content:end">
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Subtotal</div><div id="subtotalValCo" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Discount</div><div id="discountValCo" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Tax</div><div id="taxValCo" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end;font-weight:700"><div style="min-width:140px;text-align:right">Total</div><div id="totalValCo" style="min-width:120px;text-align:right">$0.00</div></div>
    </div>

    <div>
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Update Contract</button>
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
<?php foreach ($items as $it): ?>
addItemCo(<?php echo json_encode($it['description']); ?>, <?php echo json_encode((float)$it['quantity']); ?>, <?php echo json_encode((float)$it['unit_price']); ?>);
<?php endforeach; ?>
</script>
