<?php
// src/views/pages/invoices-edit.php
require_once __DIR__ . '/../../../config/db.php';
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
  <h2>Edit Invoice I-<?php echo htmlspecialchars($inv['doc_number'] ?? $inv['id']); ?><?php if (!empty($inv['project_code'])) echo ' (Project '.htmlspecialchars($inv['project_code']).')'; ?></h2>
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

    <div>
      <div style="font-weight:600;margin-bottom:8px">Items (from contract - read only)</div>
      <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px">
        <?php if (empty($items)): ?>
          <p style="color:#6b7280;margin:0">No items</p>
        <?php else: ?>
          <table style="width:100%;border-collapse:collapse">
            <thead>
              <tr style="border-bottom:2px solid #e5e7eb">
                <th style="text-align:left;padding:8px;color:#6b7280;font-weight:600">Description</th>
                <th style="text-align:right;padding:8px;color:#6b7280;font-weight:600;width:100px">Quantity</th>
                <th style="text-align:right;padding:8px;color:#6b7280;font-weight:600;width:120px">Unit Price</th>
                <th style="text-align:right;padding:8px;color:#6b7280;font-weight:600;width:120px">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $it): ?>
                <tr style="border-bottom:1px solid #e5e7eb">
                  <td style="padding:8px"><?php echo htmlspecialchars($it['description']); ?></td>
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

    <?php $pn=null; if (!empty($inv['project_code'])) { $pm=$pdo->prepare('SELECT notes FROM project_meta WHERE project_code=?'); $pm->execute([$inv['project_code']]); $pn=(string)$pm->fetchColumn(); } ?>
    <label>
      <div>Project Notes</div>
      <textarea name="project_notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Shared across related docs"><?php echo htmlspecialchars($pn ?? ''); ?></textarea>
    </label>

    <?php
      // Calculate totals from database items
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
<script>

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
