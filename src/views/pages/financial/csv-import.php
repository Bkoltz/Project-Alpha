<?php
// src/views/pages/financial/csv-import.php
// CSV import page for expenses — upload, map columns, preview, import
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';

// Fetch categories for default category dropdown
$catStmt = $pdo->prepare('SELECT id, name FROM expense_categories WHERE organization_id=1 ORDER BY name');
$catStmt->execute();
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="max-width:1000px;margin:0 auto;padding:24px">
  <div class="page-head">
    <div>
      <h2>Import Expenses from CSV</h2>
      <p class="muted-note">Upload a CSV file from Amazon, your bank, or any other source. Map columns to expense fields, preview, then import.</p>
    </div>
    <a href="/?page=financial/expenses-list" class="btn btn-sm">Back to Expenses</a>
  </div>

  <!-- Step 1: Upload -->
  <div class="card" id="uploadStep">
    <h3 class="card-title" style="margin-bottom:16px">Step 1: Upload CSV File</h3>
    <form id="csvUploadForm" enctype="multipart/form-data">
      <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_sf_token('csv')); ?>">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="phase" value="upload">

      <div class="field">
        <label class="label">CSV File</label>
        <input type="file" name="csv_file" accept=".csv,text/csv" class="input" required>
        <div class="muted-note">Max 10MB. Supported: Amazon order history, bank statement exports, or generic CSV.</div>
      </div>

      <button type="submit" class="btn btn-primary">Upload & Preview</button>
    </form>
  </div>

  <!-- Step 2: Mapping (hidden until upload succeeds) -->
  <div class="card" id="mappingStep" style="display:none;margin-top:16px">
    <h3 class="card-title" style="margin-bottom:8px">Step 2: Map Columns</h3>
    <p class="muted-note" style="margin-bottom:16px">Detected format: <strong id="detectedFormat">—</strong>. Map CSV columns to expense fields. Unmapped fields will be left empty.</p>

    <div id="mappingTable" style="margin-bottom:16px"></div>

    <div class="field">
      <label class="label">Default Category (applied to all imported expenses)</label>
      <select name="default_category_id" class="input" style="max-width:300px">
        <option value="0">— No category —</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex" style="margin-top:16px">
      <button type="button" class="btn btn-sm" id="dryRunBtn">Dry Run (Preview)</button>
      <button type="button" class="btn btn-primary" id="importBtn">Import Expenses</button>
    </div>
  </div>

  <!-- Step 3: Preview (hidden until import runs) -->
  <div class="card" id="resultStep" style="display:none;margin-top:16px">
    <h3 class="card-title" style="margin-bottom:16px">Import Results</h3>
    <div id="importResults"></div>
    <div style="margin-top:16px">
      <a href="/?page=financial/expenses-list" class="btn btn-primary">View Expenses</a>
      <button type="button" class="btn btn-sm" onclick="resetImport()">Import Another</button>
    </div>
  </div>
</div>

<script>
const csrfToken = '<?php echo htmlspecialchars(csrf_token()); ?>';
const csrfSfToken = '<?php echo htmlspecialchars(csrf_sf_token('csv')); ?>';

function appendToken(formData) {
  formData.append('csrf', csrfToken);
  formData.append('_token', csrfSfToken);
}

// Patch fetch calls to include both tokens
const originalFetch = window.fetch;
window.fetch = function(url, options = {}) {
  if (typeof url === 'string' && url.includes('?page=financial/csv-import')) {
    if (options.body instanceof FormData) {
      if (!options.body.has('csrf')) options.body.append('csrf', csrfToken);
      if (!options.body.has('_token')) options.body.append('_token', csrfSfToken);
    }
  }
  return originalFetch(url, options);
};

document.getElementById('csvUploadForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const formData = new FormData(this);

  const btn = this.querySelector('button[type="submit"]');
  btn.textContent = 'Uploading...';
  btn.disabled = true;

  try {
    const resp = await fetch('/?page=financial/csv-import', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData
    });
    const data = await resp.json();

    if (!data.success) {
      alert('Upload failed: ' + (data.error || 'Unknown error'));
      btn.textContent = 'Upload & Preview';
      btn.disabled = false;
      return;
    }

    // Show mapping step
    document.getElementById('detectedFormat').textContent = data.format.toUpperCase();
    buildMappingTable(data.headers, data.suggested_mapping, data.preview_rows);
    document.getElementById('mappingStep').style.display = 'block';
    btn.textContent = 'Upload & Preview';
    btn.disabled = false;
  } catch (err) {
    alert('Upload failed: ' + err.message);
    btn.textContent = 'Upload & Preview';
    btn.disabled = false;
  }
});

function buildMappingTable(headers, suggested, previewRows) {
  const expenseFields = {
    'expense_date': 'Expense Date',
    'vendor_name': 'Vendor Name',
    'description': 'Description',
    'amount': 'Amount',
    'tax_amount': 'Tax Amount',
    'total_amount': 'Total Amount',
    'reference_number': 'Reference Number',
    'payment_method': 'Payment Method'
  };

  let html = '<table class="pa-table"><thead><tr><th>Expense Field</th><th>CSV Column</th><th>Preview (first row)</th></tr></thead><tbody>';
  for (const [field, label] of Object.entries(expenseFields)) {
    const suggestedIdx = suggested[field] !== undefined ? suggested[field] : '';
    let options = '<option value="">— Skip —</option>';
    headers.forEach((h, i) => {
      const selected = i === suggestedIdx ? ' selected' : '';
      options += `<option value="${i}"${selected}>${h}</option>`;
    });
    const preview = previewRows[0] && previewRows[0][suggestedIdx] ? previewRows[0][suggestedIdx] : '—';
    html += `<tr><td>${label}</td><td><select class="input-sm" id="map_${field}">${options}</select></td><td class="muted text-sm">${preview}</td></tr>`;
  }
  html += '</tbody></table>';
  document.getElementById('mappingTable').innerHTML = html;
}

async function runImport(dryRun) {
  const mapping = {};
  ['expense_date', 'vendor_name', 'description', 'amount', 'tax_amount', 'total_amount', 'reference_number', 'payment_method'].forEach(field => {
    const sel = document.getElementById('map_' + field);
    mapping[field] = sel ? (sel.value !== '' ? parseInt(sel.value) : null) : null;
  });

  const defaultCat = document.querySelector('[name="default_category_id"]').value;

  const formData = new FormData();
  formData.append('csrf', csrfToken);
  formData.append('_token', csrfSfToken);
  formData.append('phase', 'import');
  formData.append('mapping', JSON.stringify(mapping));
  formData.append('default_category_id', defaultCat);
  formData.append('dry_run', dryRun ? '1' : '0');

  try {
    const resp = await fetch('/?page=financial/csv-import', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData
    });
    const data = await resp.json();

    if (!data.success) {
      alert('Import failed: ' + (data.error || 'Unknown error'));
      return;
    }

    const dryLabel = data.dry_run ? ' (Dry Run)' : '';
    let html = `<div class="alert alert-success" style="margin-bottom:12px">Import complete${dryLabel}: ${data.imported} imported, ${data.skipped} skipped.</div>`;

    if (data.errors && data.errors.length > 0) {
      html += '<div class="card-tight" style="margin-top:12px"><strong>Skipped rows:</strong><ul class="muted text-sm" style="margin-top:8px">';
      data.errors.forEach(e => { html += `<li>${e}</li>`; });
      html += '</ul></div>';
    }

    document.getElementById('importResults').innerHTML = html;
    document.getElementById('resultStep').style.display = 'block';
  } catch (err) {
    alert('Import failed: ' + err.message);
  }
}

document.getElementById('dryRunBtn').addEventListener('click', () => runImport(true));
document.getElementById('importBtn').addEventListener('click', () => runImport(false));

function resetImport() {
  document.getElementById('mappingStep').style.display = 'none';
  document.getElementById('resultStep').style.display = 'none';
  document.getElementById('csvUploadForm').reset();
}
</script>