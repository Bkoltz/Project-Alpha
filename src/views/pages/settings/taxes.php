<?php
// src/views/pages/settings/taxes.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Check for import success/error messages
$importSuccess = isset($_GET['import_success']);
$importError = isset($_GET['import_error']) ? $_GET['import_error'] : null;
$importSummary = $_SESSION['tax_import_summary'] ?? null;

// Clear session data after reading
if ($importSuccess) unset($_SESSION['tax_import_summary']);

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
    <div style="margin-bottom:20px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,0.1)">
      <div style="padding:12px 16px;background:#f9fafb;border-bottom:1px solid #e5e7eb;border-radius:8px 8px 0 0;display:flex;justify-content:space-between;align-items:center">
        <span style="font-weight:600;color:#374151"><?php echo count($taxRates); ?> Tax Rate<?php echo count($taxRates) !== 1 ? 's' : ''; ?></span>
        <span style="font-size:12px;color:#6b7280">Scroll to view all</span>
      </div>
      <div style="max-height:400px;overflow-y:auto">
        <table class="pa-table">
          <thead style="position:sticky;top:0;background:#f9fafb;z-index:1">
            <tr style="text-align:left;border-bottom:2px solid #e5e7eb">
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
                <div class="flex">
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
        <div class="label">Tax Rate Name *</div>
        <input type="text" name="name" value="<?php echo htmlspecialchars($editRow['name'] ?? ''); ?>" required 
               placeholder="e.g., California State Tax, NYC Sales Tax"
               style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:100%;font-size:14px">
        <div style="margin-top:4px;color:var(--muted);font-size:12px">A descriptive name for this tax rate</div>
      </label>
      
      <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:12px">
        <label>
          <div class="label">Country</div>
          <input type="text" name="country" value="<?php echo htmlspecialchars($editRow['country'] ?? 'USA'); ?>" 
                 placeholder="USA"
                 style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:100%;font-size:14px">
        </label>
        <label>
          <div class="label">State/Province</div>
          <input type="text" name="state" value="<?php echo htmlspecialchars($editRow['state'] ?? ''); ?>" 
                 placeholder="e.g., CA, NY"
                 style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:100%;font-size:14px">
        </label>
        <label>
          <div class="label">County</div>
          <input type="text" name="county" value="<?php echo htmlspecialchars($editRow['county'] ?? ''); ?>" 
                 placeholder="Optional"
                 style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:100%;font-size:14px">
        </label>
      </div>
      
      <label>
        <div class="label">Tax Rate (%) *</div>
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
            <div class="font-600">Active</div>
            <div style="font-size:12px;color:var(--muted)">Available for selection in documents</div>
          </div>
        </label>
        <?php if ($hasDefault): ?>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
          <input type="checkbox" name="is_default" value="1" 
                 <?php echo ($editRow['is_default'] ?? 0) ? 'checked' : ''; ?>>
          <div>
            <div class="font-600">Set as Default</div>
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
  
  <!-- Import Tax Rates Section -->
  <fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:24px;background:#fff;margin-top:24px">
    <legend style="padding:0 12px;font-weight:600;font-size:16px">📥 Import Tax Rates</legend>
    
    <p style="margin:0 0 16px;color:#6b7280;font-size:14px">
      Import county-level tax rates from SSTGB files. Upload both files and click Import.
    </p>
    
    <?php if ($importSuccess && $importSummary): ?>
      <div style="margin-bottom:16px;padding:12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px">
        <pre style="margin:0;font-family:monospace;font-size:12px;white-space:pre-wrap;color:#047857"><?php echo htmlspecialchars($importSummary); ?></pre>
      </div>
    <?php endif; ?>
    <?php if ($importError): ?>
      <div style="margin-bottom:16px;padding:12px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px">
        <div style="color:#b91c1c;font-size:13px">❌ <?php echo htmlspecialchars($importError); ?></div>
      </div>
    <?php endif; ?>
    
    <form method="post" action="/?page=settings/tax-import-handler" enctype="multipart/form-data" style="display:grid;gap:16px;max-width:600px">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      
      <div class="grid">
        <div>
          <label style="display:block;margin-bottom:6px;font-weight:600;font-size:14px">📍 FIPS County File (TXT) *</label>
          <input type="file" name="fips_file" accept=".txt" required
                 style="padding:10px 12px;border-radius:6px;border:1px solid #d1d5db;width:100%;font-size:13px;background:#fff">
          <div style="margin-top:4px;color:var(--muted);font-size:11px">Census Bureau county file (e.g., st55_wi_cou2020.txt)</div>
        </div>
        
        <div>
          <label style="display:block;margin-bottom:6px;font-weight:600;font-size:14px">💰 Tax Rates File (CSV) *</label>
          <input type="file" name="rate_file" accept=".csv" required
                 style="padding:10px 12px;border-radius:6px;border:1px solid #d1d5db;width:100%;font-size:13px;background:#fff">
          <div style="margin-top:4px;color:var(--muted);font-size:11px">SSTGB rate file (e.g., WIR032026.csv)</div>
        </div>
        
        <div>
          <label style="display:block;margin-bottom:6px;font-weight:600;font-size:14px">State Tax Rate (%)</label>
          <input type="number" step="0.01" min="0" max="20" name="state_tax_rate" value="5.00" 
                 style="padding:10px 12px;border-radius:6px;border:1px solid #d1d5db;width:120px;font-size:13px;background:#fff">
          <div style="margin-top:4px;color:var(--muted);font-size:11px">State sales tax to add (e.g., 5.00 for Wisconsin)</div>
        </div>
      </div>
      
      <button type="submit" style="padding:12px 24px;border-radius:8px;border:0;background:#059669;color:#fff;font-weight:600;cursor:pointer;font-size:14px;width:fit-content">
        📥 Import Tax Rates
      </button>
    </form>
    
    <div style="margin-top:20px;padding:12px;background:#f0f9ff;border-left:4px solid #0284c7;border-radius:4px;font-size:13px">
      <strong>📋 How it works:</strong>
      <ul style="margin:8px 0 0 16px;padding:0;color:#1e40af">
        <li>FIPS file provides county names (parsed first)</li>
        <li>Rates file provides local tax rates (county-level only, cities skipped)</li>
        <li>State tax is added to each county's local rate for the total</li>
        <li>Existing rates for the state are replaced on import</li>
      </ul>
    </div>
    
    <details style="margin-top:16px">
      <summary style="cursor:pointer;font-weight:600;color:#374151;padding:8px 0">📖 Where to get these files</summary>
      <div style="padding:12px;background:#f9fafb;border-radius:8px;margin-top:8px;font-size:13px;line-height:1.6">
        <p style="margin:0 0 12px"><strong>1. FIPS Counties (TXT file):</strong></p>
        <ul style="margin:0 0 16px 20px;padding:0">
          <li>Census Bureau: <a href="https://www.census.gov/library/reference/code-lists/ansi.html#cou" target="_blank" rel="noopener" style="color:#2563eb">ANSI FIPS County Codes</a></li>
          <li>Download the county file for your state (e.g., st55_wi_cou2020.txt)</li>
        </ul>
        <p style="margin:0 0 12px"><strong>2. Tax Rates (CSV file):</strong></p>
        <ul style="margin:0 0 16px 20px;padding:0">
          <li>Wisconsin: <a href="https://www.revenue.wi.gov/Pages/SSTGB/home.aspx" target="_blank" rel="noopener" style="color:#2563eb">WI Dept of Revenue - SSTGB Files</a></li>
          <li>Rate file: WIR032026.csv (or similar)</li>
        </ul>
      </div>
    </details>
  </fieldset>
</div>
