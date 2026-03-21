<?php
// src/views/pages/settings/taxes.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Check for import success/error messages (three importers)
$fipsSuccess = isset($_GET['fips_success']);
$fipsError = isset($_GET['fips_error']) ? $_GET['fips_error'] : null;
$fipsSummary = $_SESSION['fips_import_summary'] ?? null;

$ratesSuccess = isset($_GET['rates_success']);
$ratesError = isset($_GET['rates_error']) ? $_GET['rates_error'] : null;
$ratesSummary = $_SESSION['rates_import_summary'] ?? null;

$boundariesSuccess = isset($_GET['boundaries_success']);
$boundariesError = isset($_GET['boundaries_error']) ? $_GET['boundaries_error'] : null;
$boundariesSummary = $_SESSION['boundaries_import_summary'] ?? null;

// Legacy combined import handling
$importSuccess = isset($_GET['import_success']);
$importError = isset($_GET['import_error']) ? $_GET['import_error'] : null;
$importSummary = $_SESSION['tax_import_summary'] ?? null;

// Clear session data after reading
if ($fipsSuccess) unset($_SESSION['fips_import_summary']);
if ($ratesSuccess) unset($_SESSION['rates_import_summary']);
if ($boundariesSuccess) unset($_SESSION['boundaries_import_summary']);
if ($importSuccess) unset($_SESSION['tax_import_summary'], $_SESSION['tax_import_stats']);

// Get last import dates for each type
$lastImports = ['fips' => null, 'rates' => null, 'boundaries' => null];
try {
    $stmt = $pdo->query("SELECT import_type, MAX(imported_at) as last_import, 
                         (SELECT filename FROM tax_import_log t2 WHERE t2.import_type = t1.import_type ORDER BY imported_at DESC LIMIT 1) as filename,
                         (SELECT records_imported FROM tax_import_log t3 WHERE t3.import_type = t1.import_type ORDER BY imported_at DESC LIMIT 1) as records
                         FROM tax_import_log t1 GROUP BY import_type");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $lastImports[$row['import_type']] = $row;
    }
} catch (Throwable $e) {
    // Table may not exist yet
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
    <legend style="padding:0 12px;font-weight:600;font-size:16px">📥 Import Tax Data</legend>
    
    <p style="margin:0 0 16px;color:#6b7280;font-size:14px">
      Import tax rates from official SSTGB-compliant files. For best results, run imports in order: <strong>1) FIPS</strong> → <strong>2) Rates</strong> → <strong>3) Boundaries</strong>
    </p>
    
    <div style="display:grid;gap:20px">
      
      <!-- Step 1: FIPS Import -->
      <div style="padding:16px;border:1px solid #e5e7eb;border-radius:8px;background:#fafafa">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap">
          <span style="width:24px;height:24px;background:#3b82f6;color:#fff;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">1</span>
          <span style="font-weight:600;font-size:15px">📍 FIPS Places Import</span>
          <span style="font-size:12px;color:#6b7280">— County & city reference data</span>
          <?php if ($lastImports['fips']): ?>
            <span style="margin-left:auto;font-size:11px;color:#059669;background:#ecfdf5;padding:2px 8px;border-radius:4px">
              ✓ Last: <?php echo date('M j, Y g:ia', strtotime($lastImports['fips']['last_import'])); ?>
              (<?php echo number_format($lastImports['fips']['records']); ?> records)
            </span>
          <?php endif; ?>
        </div>
        
        <?php if ($fipsSuccess && $fipsSummary): ?>
          <div style="margin-bottom:12px;padding:12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px">
            <pre style="margin:0;font-family:monospace;font-size:12px;white-space:pre-wrap;color:#047857"><?php echo htmlspecialchars($fipsSummary); ?></pre>
          </div>
        <?php endif; ?>
        <?php if ($fipsError): ?>
          <div style="margin-bottom:12px;padding:12px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px">
            <div style="color:#b91c1c;font-size:13px"><?php echo htmlspecialchars($fipsError); ?></div>
          </div>
        <?php endif; ?>
        
        <form method="post" action="/?page=settings/fips-import-handler" enctype="multipart/form-data" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <div style="flex:1;min-width:250px">
            <input type="file" name="fips_file" accept=".txt" required
                   style="padding:8px 10px;border-radius:6px;border:1px solid #d1d5db;width:100%;font-size:13px;background:#fff">
            <div style="margin-top:4px;color:var(--muted);font-size:11px">TXT file from Census Bureau (e.g., st55_wi_cousub2020.txt)</div>
          </div>
          <button type="submit" style="padding:8px 16px;border-radius:6px;border:0;background:#3b82f6;color:#fff;font-weight:600;cursor:pointer;font-size:13px;white-space:nowrap">
            Import FIPS
          </button>
        </form>
      </div>
      
      <!-- Step 2: Rates Import -->
      <div style="padding:16px;border:1px solid #e5e7eb;border-radius:8px;background:#fafafa">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap">
          <span style="width:24px;height:24px;background:#059669;color:#fff;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">2</span>
          <span style="font-weight:600;font-size:15px">💰 Tax Rates Import</span>
          <span style="font-size:12px;color:#6b7280">— SSTGB rate file (sums all 4 rate columns)</span>
          <?php if ($lastImports['rates']): ?>
            <span style="margin-left:auto;font-size:11px;color:#059669;background:#ecfdf5;padding:2px 8px;border-radius:4px">
              ✓ Last: <?php echo date('M j, Y g:ia', strtotime($lastImports['rates']['last_import'])); ?>
              (<?php echo number_format($lastImports['rates']['records']); ?> records)
            </span>
          <?php endif; ?>
        </div>
        
        <?php if ($ratesSuccess && $ratesSummary): ?>
          <div style="margin-bottom:12px;padding:12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px">
            <pre style="margin:0;font-family:monospace;font-size:12px;white-space:pre-wrap;color:#047857"><?php echo htmlspecialchars($ratesSummary); ?></pre>
          </div>
        <?php endif; ?>
        <?php if ($ratesError): ?>
          <div style="margin-bottom:12px;padding:12px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px">
            <div style="color:#b91c1c;font-size:13px"><?php echo htmlspecialchars($ratesError); ?></div>
          </div>
        <?php endif; ?>
        
        <form method="post" action="/?page=settings/rates-import-handler" enctype="multipart/form-data" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <div style="flex:1;min-width:250px">
            <input type="file" name="rate_file" accept=".csv" required
                   style="padding:8px 10px;border-radius:6px;border:1px solid #d1d5db;width:100%;font-size:13px;background:#fff">
            <div style="margin-top:4px;color:var(--muted);font-size:11px">CSV rate file (e.g., WIR032026.csv)</div>
          </div>
          <button type="submit" style="padding:8px 16px;border-radius:6px;border:0;background:#059669;color:#fff;font-weight:600;cursor:pointer;font-size:13px;white-space:nowrap">
            Import Rates
          </button>
        </form>
      </div>
      
      <!-- Step 3: Boundaries Import -->
      <div style="padding:16px;border:1px solid #e5e7eb;border-radius:8px;background:#fafafa">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap">
          <span style="width:24px;height:24px;background:#7c3aed;color:#fff;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">3</span>
          <span style="font-weight:600;font-size:15px">🗺️ Boundaries Import</span>
          <span style="font-size:12px;color:#6b7280">— ZIP+4 to jurisdiction mapping (large file)</span>
          <?php if ($lastImports['boundaries']): ?>
            <span style="margin-left:auto;font-size:11px;color:#059669;background:#ecfdf5;padding:2px 8px;border-radius:4px">
              ✓ Last: <?php echo date('M j, Y g:ia', strtotime($lastImports['boundaries']['last_import'])); ?>
              (<?php echo number_format($lastImports['boundaries']['records']); ?> records)
            </span>
          <?php endif; ?>
        </div>
        
        <?php if ($boundariesSuccess && $boundariesSummary): ?>
          <div style="margin-bottom:12px;padding:12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px">
            <pre style="margin:0;font-family:monospace;font-size:12px;white-space:pre-wrap;color:#047857"><?php echo htmlspecialchars($boundariesSummary); ?></pre>
          </div>
        <?php endif; ?>
        <?php if ($boundariesError): ?>
          <div style="margin-bottom:12px;padding:12px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px">
            <div style="color:#b91c1c;font-size:13px"><?php echo htmlspecialchars($boundariesError); ?></div>
          </div>
        <?php endif; ?>
        
        <form method="post" action="/?page=settings/boundaries-import-handler" enctype="multipart/form-data" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <div style="flex:1;min-width:250px">
            <input type="file" name="boundary_file" accept=".csv" required
                   style="padding:8px 10px;border-radius:6px;border:1px solid #d1d5db;width:100%;font-size:13px;background:#fff">
            <div style="margin-top:4px;color:var(--muted);font-size:11px">CSV boundary file (e.g., WIB032026.csv) — may take a while for large files</div>
          </div>
          <button type="submit" style="padding:8px 16px;border-radius:6px;border:0;background:#7c3aed;color:#fff;font-weight:600;cursor:pointer;font-size:13px;white-space:nowrap">
            Import Boundaries
          </button>
        </form>
      </div>
      
    </div>
    
    <div style="margin-top:20px;padding:12px;background:#f0f9ff;border-left:4px solid #0284c7;border-radius:4px;font-size:13px">
      <strong>📋 Import Process:</strong>
      <ul style="margin:8px 0 0 16px;padding:0;color:#1e40af">
        <li><strong>FIPS</strong> → provides county/city names for jurisdictions</li>
        <li><strong>Rates</strong> → imports tax percentages (all 4 rate columns are summed)</li>
        <li><strong>Boundaries</strong> → maps ZIP+4 ranges to jurisdictions, flags complex ZIPs</li>
      </ul>
    </div>
    
    <details style="margin-top:16px">
      <summary style="cursor:pointer;font-weight:600;color:#374151;padding:8px 0">📖 Where to get these files</summary>
      <div style="padding:12px;background:#f9fafb;border-radius:8px;margin-top:8px;font-size:13px;line-height:1.6">
        <p style="margin:0 0 12px"><strong>1. FIPS Places (TXT file):</strong></p>
        <ul style="margin:0 0 16px 20px;padding:0">
          <li>Census Bureau: <a href="https://www.census.gov/library/reference/code-lists/ansi.html#cou" target="_blank" rel="noopener" style="color:#2563eb">ANSI FIPS County Codes</a></li>
          <li>Download the county subdivision file for your state (e.g., st55_wi_cousub2020.txt)</li>
        </ul>
        <p style="margin:0 0 12px"><strong>2. Tax Rates & Boundaries (CSV files):</strong></p>
        <ul style="margin:0 0 16px 20px;padding:0">
          <li>Wisconsin: <a href="https://www.revenue.wi.gov/Pages/SSTGB/home.aspx" target="_blank" rel="noopener" style="color:#2563eb">WI Dept of Revenue - SSTGB Files</a></li>
          <li>Rate file: WIR032026.csv (or similar naming)</li>
          <li>Boundary file: WIB032026.csv (or similar naming)</li>
        </ul>
        <p style="margin:0 0 12px"><strong>SSTGB (All States):</strong></p>
        <ul style="margin:0 0 0 20px;padding:0">
          <li>Streamlined Sales Tax: <a href="https://www.streamlinedsalestax.org/" target="_blank" rel="noopener" style="color:#2563eb">Official Website</a></li>
        </ul>
      </div>
    </details>
  </fieldset>
</div>
