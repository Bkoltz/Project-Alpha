<?php
// src/views/pages/settings/taxes.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Check for import success/error messages
$importSuccess = isset($_GET['import_success']);
$importError = isset($_GET['import_error']) ? $_GET['import_error'] : null;
$importSummary = $_SESSION['tax_import_summary'] ?? null;
$importStats = $_SESSION['tax_import_stats'] ?? null;

// Clear session data after reading
if ($importSuccess) {
    unset($_SESSION['tax_import_summary'], $_SESSION['tax_import_stats']);
}

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
        <table style="width:100%;border-collapse:collapse">
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
  
  <!-- Import Tax Rates Section -->
  <fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:24px;background:#fff;margin-top:24px">
    <legend style="padding:0 12px;font-weight:600;font-size:16px">📥 Import Tax Rates</legend>
    
    <?php if ($importSuccess && $importSummary): ?>
      <div style="margin-bottom:20px;padding:16px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px">
        <div style="font-weight:600;color:#065f46;margin-bottom:8px">✓ Import Completed</div>
        <pre style="margin:0;font-family:monospace;font-size:13px;white-space:pre-wrap;color:#047857"><?php echo htmlspecialchars($importSummary); ?></pre>
      </div>
    <?php endif; ?>
    
    <?php if ($importError): ?>
      <div style="margin-bottom:20px;padding:16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px">
        <div style="font-weight:600;color:#991b1b;margin-bottom:4px">⚠ Import Error</div>
        <div style="color:#b91c1c"><?php echo htmlspecialchars($importError); ?></div>
      </div>
    <?php endif; ?>
    
    <p style="margin:0 0 16px;color:#6b7280;font-size:14px">
      Import tax rates from official government SSTGB-compliant files. Upload a ZIP containing FIPS and boundary files, plus the rate CSV.
    </p>
    
    <form method="post" action="/?page=settings/tax-rates-import-handler" enctype="multipart/form-data" style="display:grid;gap:16px;max-width:720px">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      
      <div style="display:grid;gap:16px">
        <label>
          <div style="margin-bottom:6px;font-weight:600">
            📦 Reference Files (.zip) *
            <span style="font-weight:normal;color:#6b7280;font-size:12px">— Contains FIPS county file + boundary file</span>
          </div>
          <input type="file" name="zip_file" accept=".zip" required
                 style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:100%;font-size:14px;background:#f9fafb">
          <div style="margin-top:4px;color:var(--muted);font-size:12px">
            ZIP should contain: <code>st55_wi_cou2020.txt</code> (FIPS) and <code>WIB032026.csv</code> (boundaries)
          </div>
        </label>
        
        <label>
          <div style="margin-bottom:6px;font-weight:600">
            💰 SSTGB Tax Rate File (.csv) *
            <span style="font-weight:normal;color:#6b7280;font-size:12px">— Downloaded separately from state website</span>
          </div>
          <input type="file" name="rate_file" accept=".csv" required
                 style="padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;width:100%;font-size:14px;background:#f9fafb">
          <div style="margin-top:4px;color:var(--muted);font-size:12px">
            Format: <code>55,00,009,0.005,0.005,0.005,0.005,20180101,99991231</code>
          </div>
        </label>
      </div>
      
      <div style="padding:12px;background:#f0f9ff;border-left:4px solid #0284c7;border-radius:4px;font-size:13px">
        <strong>📋 What happens during import:</strong>
        <ul style="margin:8px 0 0 16px;padding:0;color:#1e40af">
          <li>FIPS file → maps county codes to county names</li>
          <li>Boundary file → maps ZIP+4 codes to tax jurisdictions</li>
          <li>Rate file → contains actual tax rates (all 4 rate columns are summed)</li>
          <li>Complex ZIPs (with city/special taxes) are flagged for ZIP+4 lookup</li>
          <li>Only currently active rates are imported (based on date ranges)</li>
        </ul>
      </div>
      
      <div style="display:flex;gap:12px;padding-top:8px">
        <button type="submit" 
                style="padding:10px 20px;border-radius:8px;border:0;background:#059669;color:#fff;font-weight:600;cursor:pointer;font-size:14px">
          📥 Import Tax Rates
        </button>
      </div>
    </form>
    
    <div style="margin-top:20px;padding:12px;background:#fefce8;border:1px solid #fde68a;border-radius:8px;font-size:13px">
      <strong>📅 Recommended Import Schedule:</strong>
      <p style="margin:8px 0 0;color:#854d0e">
        Most states update tax rates annually. We recommend importing new tax rate files once per year (typically in January) or whenever your state publishes updated data.
      </p>
    </div>
    
    <details style="margin-top:16px">
      <summary style="cursor:pointer;font-weight:600;color:#374151;padding:8px 0">📖 Where to get these files</summary>
      <div style="padding:12px;background:#f9fafb;border-radius:8px;margin-top:8px;font-size:13px;line-height:1.6">
        <p style="margin:0 0 12px"><strong>State Tax Authority Websites:</strong></p>
        <ul style="margin:0 0 16px 20px;padding:0">
          <li>Wisconsin: <a href="https://www.revenue.wi.gov/Pages/SSTGB/home.aspx" target="_blank" rel="noopener" style="color:#2563eb">WI Dept of Revenue - SSTGB Files</a></li>
          <li>Look for "Boundary Database" and "Rate Database" downloads</li>
        </ul>
        <p style="margin:0 0 12px"><strong>FIPS County Reference:</strong></p>
        <ul style="margin:0 0 16px 20px;padding:0">
          <li>Census Bureau: <a href="https://www.census.gov/geographies/reference-files/time-series/geo/gazetteer-files.html" target="_blank" rel="noopener" style="color:#2563eb">Gazetteer Files</a></li>
        </ul>
        <p style="margin:0 0 12px"><strong>SSTGB (All States):</strong></p>
        <ul style="margin:0 0 0 20px;padding:0">
          <li>Streamlined Sales Tax: <a href="https://www.streamlinedsalestax.org/" target="_blank" rel="noopener" style="color:#2563eb">Official Website</a></li>
        </ul>
      </div>
    </details>
  </fieldset>
</div>
