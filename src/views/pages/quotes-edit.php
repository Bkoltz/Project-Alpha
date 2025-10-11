<?php
// src/views/pages/quotes-edit.php
require_once __DIR__ . '/../../config/db.php';
$id = (int)($_GET['id'] ?? 0);
$q = $pdo->prepare('SELECT * FROM quotes WHERE id=?');
$q->execute([$id]);
$quote = $q->fetch(PDO::FETCH_ASSOC);
if(!$quote){ echo '<p>Quote not found</p>'; return; }
$items = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id=?');
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll();
?>
<section>
  <h2>Edit Quote Q-<?php echo htmlspecialchars($quote['doc_number'] ?? $quote['id']); ?><?php if (!empty($quote['project_code'])) echo ' (Project '.htmlspecialchars($quote['project_code']).')'; ?></h2>
  <form id="quoteEditForm" method="post" action="/?page=quotes-update" style="display:grid;gap:16px;max-width:900px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="id" value="<?php echo (int)$quote['id']; ?>">
    <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">
      <label>
        <div>Client</div>
        <select required name="client_id" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <?php foreach ($clients as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)$quote['client_id']===(int)$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <div>Tax (%)</div>
        <input id="taxPercent" type="number" step="0.01" name="tax_percent" value="<?php echo htmlspecialchars($quote['tax_percent']); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Discount Type</div>
        <select id="discountType" name="discount_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none" <?php echo $quote['discount_type']==='none'?'selected':''; ?>>None</option>
          <option value="percent" <?php echo $quote['discount_type']==='percent'?'selected':''; ?>>Percent</option>
          <option value="fixed" <?php echo $quote['discount_type']==='fixed'?'selected':''; ?>>Fixed $</option>
        </select>
      </label>
      <label>
        <div>Discount Value</div>
        <input id="discountValue" type="number" step="0.01" name="discount_value" value="<?php echo htmlspecialchars($quote['discount_value']); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </div>

    <div>
      <div style="font-weight:600;margin-bottom:8px">Items</div>
      <div id="items" style="display:grid;gap:8px"></div>
      <button type="button" onclick="addItem()" style="margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">+ Add Item</button>
    </div>

    <?php 
      $pn=null; $pt=null;
      if (!empty($quote['project_code'])) {
        try {
          $pm=$pdo->prepare('SELECT notes, terms FROM project_meta WHERE project_code=?');
          $pm->execute([$quote['project_code']]);
          $row=$pm->fetch(PDO::FETCH_ASSOC);
          if ($row) { $pn=(string)($row['notes'] ?? ''); $pt=(string)($row['terms'] ?? ''); }
        } catch (Throwable $e) {
          try {
            $pm=$pdo->prepare('SELECT notes FROM project_meta WHERE project_code=?');
            $pm->execute([$quote['project_code']]);
            $row=$pm->fetch(PDO::FETCH_ASSOC);
            if ($row) { $pn=(string)($row['notes'] ?? ''); }
          } catch (Throwable $e2) { /* ignore */ }
        }
      }
    ?>
    <label>
      <div>Project Notes</div>
      <textarea name="project_notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Shared across related docs"><?php echo htmlspecialchars($pn ?? ''); ?></textarea>
    </label>
    <label>
      <div>Project Terms (override default terms for this project)</div>
      <textarea name="project_terms" rows="6" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="If set, used for all quotes/contracts under this project"><?php echo htmlspecialchars($pt ?? ''); ?></textarea>
    </label>

    <div id="totals" style="margin-top:8px;display:grid;gap:6px;justify-content:end">
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Subtotal</div><div id="subtotalVal" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Discount</div><div id="discountVal" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Tax</div><div id="taxVal" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end;font-weight:700"><div style="min-width:140px;text-align:right">Total</div><div id="totalVal" style="min-width:120px;text-align:right">$0.00</div></div>
    </div>

    <div>
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Update Quote</button>
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
  var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e=>parseFloat(e.value)||0);
  var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e=>parseFloat(e.value)||0);
  var subtotal = 0; for (var i=0;i<qtys.length;i++){ subtotal += qtys[i]*prices[i]; }
  var dtype = document.getElementById('discountType').value;
  var dval = parseFloat(document.getElementById('discountValue').value)||0;
  var taxp = parseFloat(document.getElementById('taxPercent').value)||0;
  var discount = 0; if (dtype==='percent'){ discount = Math.max(0, Math.min(100,dval))*subtotal/100; } else if (dtype==='fixed'){ discount = Math.max(0,dval); }
  var taxable = Math.max(0, subtotal - discount);
  var tax = Math.max(0, taxp)*taxable/100;
  var total = Math.max(0, taxable + tax);
  document.getElementById('subtotalVal').textContent = money(subtotal);
  document.getElementById('discountVal').textContent = money(discount);
  document.getElementById('taxVal').textContent = money(tax);
  document.getElementById('totalVal').textContent = money(total);
}
['discountType','discountValue','taxPercent'].forEach(id=>document.getElementById(id).addEventListener('input', recalc));
<?php foreach ($items as $it): ?>
addItem(<?php echo json_encode($it['description']); ?>, <?php echo json_encode((float)$it['quantity']); ?>, <?php echo json_encode((float)$it['unit_price']); ?>);
<?php endforeach; ?>
</script>
