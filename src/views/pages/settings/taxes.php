<?php
// src/views/pages/settings/taxes.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$editTaxId = (int)($_GET['edit_tax_id'] ?? 0);
$taxRates = [];
// Detect whether the tax_rates table includes an `is_default` column (older DBs may not)
$hasDefault = false;
try {
  $hasDefault = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tax_rates' AND COLUMN_NAME='is_default'")->fetchColumn();
} catch (Throwable $_e) {
  // ignore
}
try {
  $cols = 'id, name, country, state, county, rate, is_active' . ($hasDefault ? ', is_default' : '') . ', created_at';
  $order = $hasDefault ? 'is_default DESC, country, state, county, name' : 'country, state, county, name';
  $taxRates = $pdo->query("SELECT {$cols} FROM tax_rates ORDER BY {$order}")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // ignore if table doesn't exist or other DB issues
}
?>

<div style="max-width:1000px">
  <h2 style="margin:0 0 8px 0">Tax Rates</h2>
  <p style="margin:0 0 24px 0;color:var(--muted)">Manage predefined tax rates for different jurisdictions. Select a tax rate when creating documents to auto-populate tax calculations.</p>

  <?php if ($taxRates): ?>
    <div style="overflow:auto;margin-bottom:20px">
      <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1)">
        <thead>
          <tr style="text-align:left;border-bottom:2px solid #e5e7eb;background:#f9fafb">
            <th style="padding:12px">Name</th>
            <th style="padding:12px">Country</th>
            <th style="padding:12px">State</th>
            <th style="padding:12px">County</th>
            <th style="padding:12px">Rate %</th>
            <th style="padding:12px">Status</th>
            <?php if ($hasDefault): ?><th style="padding:12px">Default</th><?php endif; ?>
            <th style="padding:12px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($taxRates as $tr): ?>
            <tr style="border-bottom:1px solid #f3f4f6">
              <td style="padding:12px;font-weight:600"><?php echo htmlspecialchars($tr['name']); ?></td>
              <td style="padding:12px"><?php echo htmlspecialchars($tr['country'] ?: '—'); ?></td>
              <td style="padding:12px"><?php echo htmlspecialchars($tr['state'] ?: '—'); ?></td>
              <td style="padding:12px"><?php echo htmlspecialchars($tr['county'] ?: '—'); ?></td>
              <td style="padding:12px"><?php echo htmlspecialchars($tr['rate']); ?>%</td>
              <td style="padding:12px">
                <?php if ($tr['is_active']): ?>
                  <span style="padding:4px 8px;border-radius:6px;background:#d1fae5;color:#065f46;font-size:12px;font-weight:600">Active</span>
                <?php else: ?>
                  <span style="padding:4px 8px;border-radius:6px;background:#f3f4f6;color:#6b7280;font-size:12px">Inactive</span>
                <?php endif; ?>
              </td>
              <?php if ($hasDefault): ?>
              <td style="padding:12px">
                <?php if ($tr['is_default']): ?>
                  <span style="padding:4px 8px;border-radius:6px;background:#dbeafe;color:#1e40af;font-size:12px;font-weight:600">✓ Default</span>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <?php endif; ?>
              <td style="padding:12px">
                <div style="display:flex;gap:8px">
                  <a href="/?page=settings&tab=taxes&edit_tax_id=<?php echo (int)$tr['id']; ?>" 
                     style="padding:6px 12px;border-radius:6px;border:1px solid #d1d5db;background:#fff;text-decoration:none;color:inherit;font-size:13px">
                    Edit
                  </a>
                  <form method="post" action="/?page=settings/tax-rates-handler" style="display:inline" onsubmit="return confirm('Delete this tax rate?');">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$tr['id']; ?>">
                    <button type="submit" style="padding:6px 12px;border-radius:6px;border:1px solid #fca5a5;background:#fff;color:#991b1b;font-size:13px;cursor:pointer">
                      Delete
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div style="padding:40px;text-align:center;border:2px dashed #e5e7eb;border-radius:8px;background:#fafafa;margin-bottom:20px">
      <div style="font-size:48px;margin-bottom:12px">📋</div>
      <div style="font-size:16px;font-weight:600;margin-bottom:8px;color:#374151">No Tax Rates Configured</div>
      <div style="color:var(--muted);font-size:14px">Create your first tax rate to get started</div>
    </div>
  <?php endif; ?>

  <?php
    // Prefill edit values when editing
    $editRow = null;
    if ($editTaxId > 0) {
      foreach ($taxRates as $tr) { if ((int)$tr['id'] === $editTaxId) { $editRow = $tr; break; } }
    }
  ?>

  <fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:24px;background:#fff">
    <legend style="padding:0 12px;font-weight:600;font-size:16px"><?php echo $editRow ? 'Edit Tax Rate' : 'Add New Tax Rate'; ?></legend>
    
    <form method="post" action="/?page=settings/tax-rates-handler" style="display:grid;gap:16px;max-width:720px">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?php echo (int)($editRow['id'] ?? 0); ?>">
      
      <label>
        <div style="margin-bottom:6px;font-weight:600">Tax Rate Name *</div>
        <input type="text" name="name" value="<?php echo htmlspecialchars($editRow['name'] ?? ''); ?>" required 
               placeholder="e.g., California State Tax, NYC Sales Tax"
               style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:100%;font-size:14px">
        <div style="margin-top:4px;color:var(--muted);font-size:12px">A descriptive name for this tax rate</div>
      </label>
      
      <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:12px">
        <label>
          <div style="margin-bottom:6px;font-weight:600">Country</div>
          <input type="text" name="country" value="<?php echo htmlspecialchars($editRow['country'] ?? 'USA'); ?>" 
                 placeholder="USA"
                 style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:100%;font-size:14px">
        </label>
        <label>
          <div style="margin-bottom:6px;font-weight:600">State/Province</div>
          <input type="text" name="state" value="<?php echo htmlspecialchars($editRow['state'] ?? ''); ?>" 
                 placeholder="e.g., CA, NY"
                 style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:100%;font-size:14px">
        </label>
        <label>
          <div style="margin-bottom:6px;font-weight:600">County</div>
          <input type="text" name="county" value="<?php echo htmlspecialchars($editRow['county'] ?? ''); ?>" 
                 placeholder="Optional"
                 style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:100%;font-size:14px">
        </label>
      </div>
      
      <label>
        <div style="margin-bottom:6px;font-weight:600">Tax Rate (%) *</div>
        <input type="number" step="0.01" min="0" max="100" name="rate" 
               value="<?php echo htmlspecialchars($editRow['rate'] ?? '0.00'); ?>" 
               required
               placeholder="e.g., 7.5"
               style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:200px;font-size:14px">
        <div style="margin-top:4px;color:var(--muted);font-size:12px">Enter the percentage as a decimal (e.g., 7.5 for 7.5%)</div>
      </label>
      
      <div style="display:flex;gap:24px;padding:16px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
          <input type="checkbox" name="is_active" value="1" 
                 <?php echo (!isset($editRow) || ($editRow['is_active'] ?? 1)) ? 'checked' : ''; ?>>
          <div>
            <div style="font-weight:600">Active</div>
            <div style="font-size:12px;color:var(--muted)">Available for selection in documents</div>
          </div>
        </label>
        <?php if ($hasDefault): ?>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
          <input type="checkbox" name="is_default" value="1" 
                 <?php echo ($editRow['is_default'] ?? 0) ? 'checked' : ''; ?>>
          <div>
            <div style="font-weight:600">Set as Default</div>
            <div style="font-size:12px;color:var(--muted)">Auto-fill this rate when creating documents</div>
          </div>
        </label>
        <?php endif; ?>
      </div>
      
      <div style="display:flex;gap:12px;padding-top:8px">
        <button type="submit" 
                style="padding:10px 20px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer;font-size:14px">
          <?php echo $editRow ? 'Update Tax Rate' : 'Save Tax Rate'; ?>
        </button>
        <?php if ($editRow): ?>
          <a href="/?page=settings&tab=taxes" 
             style="padding:10px 20px;border-radius:8px;border:1px solid #d1d5db;background:#fff;text-decoration:none;color:inherit;display:inline-block;font-size:14px">
            Cancel
          </a>
        <?php endif; ?>
      </div>
    </form>
  </fieldset>
  
  <div style="margin-top:24px;padding:16px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:13px;line-height:1.6">
    <strong>💡 How Tax Rates Work:</strong>
    <ul style="margin:8px 0 0 20px;padding:0">
      <li>When creating documents (quotes, contracts, invoices), you can select a predefined tax rate or manually enter a percentage.</li>
      <li>The <strong>default tax rate</strong> will be automatically selected when creating new documents.</li>
      <li>Only <strong>one</strong> tax rate can be set as default at a time.</li>
      <li>Inactive tax rates won't appear in document creation forms but remain in the system for historical records.</li>
    </ul>
  </div>
</div>
