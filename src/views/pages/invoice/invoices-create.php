<?php
// src/views/pages/invoices-create.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/document_fields.php';
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll();
$netDays = (int)($appConfig['net_terms_days'] ?? 30); if ($netDays < 0) { $netDays = 0; }
$defaultDue = date('Y-m-d', strtotime('+' . $netDays . ' days'));
?>
<section>
  <h2>Create Invoice</h2>
  <div id="taxExemptBannerInv" style="display:none;margin:12px 0;padding:12px 16px;border-radius:8px;background:#fef3c7;border:1px solid #fbbf24;color:#78350f">
    <strong>ℹ️ Tax Exempt Organization:</strong> The selected client's organization has a tax-exempt form on file. You can still choose whether to charge taxes.
  </div>
  <form id="invoiceForm" method="post" action="/?page=invoices-create" style="display:grid;gap:16px;max-width:900px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr 1fr">
      <label style="position:relative">
        <div>Client</div>
        <input id="clientInputInv" type="text" placeholder="Type client name..." autocomplete="off" class="input">
        <input id="clientIdInv" type="hidden" name="client_id">
        <div id="clientSuggestInv" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
      </label>
      <label>
        <div>Due Date</div>
        <input type="date" name="due_date" value="<?php echo htmlspecialchars($defaultDue); ?>" class="input">
      </label>
      <label>
        <div>Tax (%)</div>
        <input id="taxPercentInv" type="number" step="0.01" name="tax_percent" value="0" class="input">
      </label>
      <label>
        <div>Discount Type</div>
        <select id="discountTypeInv" name="discount_type" class="input">
          <option value="none">None</option>
          <option value="percent">Percent</option>
          <option value="fixed">Fixed $</option>
        </select>
      </label>
      <label>
        <div>Discount Value</div>
        <input id="discountValueInv" type="number" step="0.01" name="discount_value" value="0" class="input">
      </label>
    </div>

    <div id="customFieldsContainerInv">
    <?php
    // Render custom fields for regular invoices
    echo renderDocumentCustomFields($pdo, 'regular', [], 'Inv');
    ?>
    </div>

    <div id="projectSectionInv" style="display:none;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb;margin:12px 0">
      <h3 style="margin:0 0 12px 0;color:#374151">Project Association</h3>
      <div class="grid">
        <label>
          <div>Add to Existing Project</div>
          <select id="projectSelectInv" name="project_id" class="input">
            <option value="">-- Select Project --</option>
          </select>
        </label>
        <div style="text-align:center;color:#6b7280;font-size:13px">or</div>
        <div>
          <button type="button" id="createProjectBtnInv" style="padding:10px 16px;border-radius:8px;border:1px solid #ddd;background:#fff;width:100%">Create New Project</button>
        </div>
      </div>
    </div>

    <div>
      <div style="font-weight:600;margin-bottom:8px">Items</div>
      <div id="itemsInv" class="grid"></div>
      <button type="button" onclick="addItemInv()" style="margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">+ Add Item</button>
    </div>

    <label>
      <div>Job Notes (shared across related docs)</div>
      <textarea name="project_notes" rows="3" class="input" placeholder="Notes visible to you (not the client PDF)"></textarea>
    </label>

    <div id="totalsInv" style="margin-top:8px;display:grid;gap:6px;justify-content:end">
      <div class="flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Subtotal</div><div id="subtotalValInv" class="text-right" style="min-width:120px">$0.00</div></div>
      <div class="flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Discount</div><div id="discountValInv" class="text-right" style="min-width:120px">$0.00</div></div>
      <div class="flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Tax</div><div id="taxValInv" class="text-right" style="min-width:120px">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end;font-weight:700"><div class="text-right" style="min-width:140px">Total</div><div id="totalValInv" class="text-right" style="min-width:120px">$0.00</div></div>
    </div>

    <div>
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Create Invoice</button>
    </div>
  </form>

  <script src="js/invoices-create-logic.js" defer></script>
</section>
