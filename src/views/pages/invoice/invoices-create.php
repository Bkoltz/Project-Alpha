<?php
// src/views/pages/invoice/invoices-create.php
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
      <?php if (!empty($appConfig['multi_brand_enabled'])):
        $__bsw = $pdo->query('SELECT id, name FROM organizations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC); ?>
      <label style="grid-column:1/-1">
        <div>Brand / Organization</div>
        <select class="brand-switcher" data-page="invoice" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="">All brands</option>
          <?php foreach ($__bsw as $b): ?><option value="<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option><?php endforeach; ?>
        </select>
        <div style="font-size:0.85em;color:#666;margin-top:4px">Filter clients by brand. Selecting a client sets the organization automatically.</div>
      </label>
      <?php endif; ?>

      <label style="position:relative">
        <div>Client</div>
        <input id="clientInputInv" type="text" placeholder="Type client name..." autocomplete="off" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <input id="clientIdInv" type="hidden" name="client_id">
        <div id="clientSuggestInv" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
      </label>
      <label>
        <div>Due Date</div>
        <input type="date" name="due_date" value="<?php echo htmlspecialchars($defaultDue); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Tax (%)</div>
        <input id="taxPercentInv" type="number" step="0.01" name="tax_percent" value="0" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Discount Type</div>
        <select id="discountTypeInv" name="discount_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <option value="none">None</option>
          <option value="percent">Percent</option>
          <option value="fixed">Fixed $</option>
        </select>
      </label>
      <label>
        <div>Discount Value</div>
        <input id="discountValueInv" type="number" step="0.01" name="discount_value" value="0" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
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
      <div style="display:grid;gap:12px">
        <label>
          <div>Add to Existing Project</div>
          <select id="projectSelectInv" name="project_id" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
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
      <div id="itemsInv" style="display:grid;gap:8px"></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="button" onclick="addItemInv()" style="padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">+ Add Item</button>
        <button type="button" id="btnAddFromTrackedTime" style="padding:8px 12px;border-radius:8px;border:1px solid #2ea3d6;background:#eff6ff;color:#0b4a6a;font-weight:600">+ Add from Tracked Time</button>
      </div>
    </div>

    <label>
      <div>Job Notes (shared across related docs)</div>
      <textarea name="project_notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Notes visible to you (not the client PDF)"></textarea>
    </label>

    <div id="totalsInv" style="margin-top:8px;display:grid;gap:6px;justify-content:end">
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Subtotal</div><div id="subtotalValInv" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Discount</div><div id="discountValInv" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end"><div style="min-width:140px;text-align:right;color:var(--muted)">Tax</div><div id="taxValInv" style="min-width:120px;text-align:right">$0.00</div></div>
      <div style="display:flex;gap:16px;justify-content:flex-end;font-weight:700"><div style="min-width:140px;text-align:right">Total</div><div id="totalValInv" style="min-width:120px;text-align:right">$0.00</div></div>
    </div>

    <div>
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Create Invoice</button>
    </div>
  </form>
</section>

<!-- Tracked Time Modal -->
<div id="trackedTimeModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card" style="width:100%;max-width:800px;max-height:90vh;overflow:auto">
    <div class="card-head">
      <h3 class="card-title">Add from Tracked Time</h3>
      <button type="button" id="closeTrackedTimeModal" class="btn btn-sm">Close</button>
    </div>
    <div id="trackedTimeLoading" style="padding:30px;text-align:center;color:var(--muted)">Loading unbilled time entries…</div>
    <div id="trackedTimeEmpty" style="display:none;padding:30px;text-align:center;color:var(--muted)">No unbilled billable time entries available.</div>
    <form id="trackedTimeForm" style="display:none">
      <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
      <div class="expense-table-wrap">
        <table class="pa-table expense-table">
          <thead>
            <tr>
              <th style="width:40px"><input type="checkbox" id="selectAllTrackedTime"></th>
              <th>Date</th>
              <th>Description</th>
              <th>Hours</th>
              <th>Rate</th>
              <th>Amount</th>
            </tr>
          </thead>
          <tbody id="trackedTimeTbody"></tbody>
        </table>
      </div>
      <div class="expense-filter-actions" style="margin-top:16px">
        <button type="button" id="btnAddSelectedTrackedTime" class="btn btn-primary">Add Selected to Invoice</button>
      </div>
    </form>
  </div>
</div>

<script src="/assets/js/brand-switcher.js" defer></script>
<script src="/assets/js/invoices-create-logic.js" defer></script>
