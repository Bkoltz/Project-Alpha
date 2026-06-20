<?php
// src/views/pages/financial/expense-report.php
// Filterable expense report with CSV export
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/format.php';

$orgId = 1;

// Fetch filter options
$catStmt = $pdo->prepare('SELECT id, name FROM expense_categories WHERE organization_id=? ORDER BY name');
$catStmt->execute([$orgId]);
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

$vendorStmt = $pdo->prepare('SELECT id, name FROM vendors WHERE organization_id=? AND is_active=1 ORDER BY name');
$vendorStmt->execute([$orgId]);
$vendors = $vendorStmt->fetchAll(PDO::FETCH_ASSOC);

$clientStmt = $pdo->query('SELECT id, name FROM clients ORDER BY name');
$clients = $clientStmt->fetchAll(PDO::FETCH_ASSOC);

// Filters
$start = $_GET['start'] ?? date('Y') . '-01-01';
$end = $_GET['end'] ?? date('Y') . '-12-31';
$categoryId = (int)($_GET['category_id'] ?? 0);
$vendorId = (int)($_GET['vendor_id'] ?? 0);
$clientId = (int)($_GET['client_id'] ?? 0);
$billable = $_GET['billable'] ?? '';
$taxDeductible = $_GET['tax_deductible'] ?? '';
$status = $_GET['status'] ?? '';
$groupBy = $_GET['group_by'] ?? 'none';

$where = ['e.organization_id = ?'];
$params = [$orgId];

if ($start) { $where[] = 'e.expense_date >= ?'; $params[] = $start; }
if ($end) { $where[] = 'e.expense_date <= ?'; $params[] = $end; }
if ($categoryId > 0) { $where[] = 'e.category_id = ?'; $params[] = $categoryId; }
if ($vendorId > 0) { $where[] = 'e.vendor_id = ?'; $params[] = $vendorId; }
if ($clientId > 0) { $where[] = 'e.client_id = ?'; $params[] = $clientId; }
if ($billable === '1') { $where[] = 'e.is_billable = 1'; }
if ($billable === '0') { $where[] = 'e.is_billable = 0'; }
if ($taxDeductible === '1') { $where[] = 'e.is_tax_deductible = 1'; }
if ($taxDeductible === '0') { $where[] = 'e.is_tax_deductible = 0'; }
if ($status) { $where[] = 'e.status = ?'; $params[] = $status; }

$whereSQL = implode(' AND ', $where);

// Summary totals
$summaryStmt = $pdo->prepare("
    SELECT COALESCE(SUM(e.amount),0) as total_amount,
           COALESCE(SUM(e.tax_amount),0) as total_tax,
           COALESCE(SUM(e.total_amount),0) as grand_total,
           COALESCE(SUM(CASE WHEN e.is_billable=1 THEN e.total_amount ELSE 0 END),0) as billable_total,
           COALESCE(SUM(CASE WHEN e.is_tax_deductible=1 THEN e.total_amount ELSE 0 END),0) as deductible_total,
           COUNT(*) as count
    FROM expenses e WHERE {$whereSQL}
");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

// Build query based on group_by
if ($groupBy === 'category') {
    $sql = "
        SELECT ec.name as group_name, COALESCE(SUM(e.total_amount),0) as total, COUNT(e.id) as count
        FROM expense_categories ec
        LEFT JOIN expenses e ON e.category_id = ec.id AND " . implode(' AND ', array_map(function($w) { return 'e.' . $w; }, $where)) . "
        WHERE ec.organization_id = ?
        GROUP BY ec.id ORDER BY total DESC
    ";
    // This approach is complex with the WHERE clause; simpler to do it directly:
    $groupStmt = $pdo->prepare("
        SELECT ec.name as group_name, COALESCE(SUM(e.total_amount),0) as total, COUNT(e.id) as count
        FROM expenses e
        LEFT JOIN expense_categories ec ON ec.id = e.category_id
        WHERE {$whereSQL}
        GROUP BY ec.id, ec.name ORDER BY total DESC
    ");
    $groupStmt->execute($params);
    $grouped = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($groupBy === 'vendor') {
    $groupStmt = $pdo->prepare("
        SELECT COALESCE(v.name,'(No Vendor)') as group_name, COALESCE(SUM(e.total_amount),0) as total, COUNT(e.id) as count
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        WHERE {$whereSQL}
        GROUP BY v.id, v.name ORDER BY total DESC
    ");
    $groupStmt->execute($params);
    $grouped = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($groupBy === 'month') {
    $groupStmt = $pdo->prepare("
        SELECT DATE_FORMAT(e.expense_date, '%Y-%m') as group_name, COALESCE(SUM(e.total_amount),0) as total, COUNT(e.id) as count
        FROM expenses e
        WHERE {$whereSQL}
        GROUP BY DATE_FORMAT(e.expense_date, '%Y-%m') ORDER BY group_name
    ");
    $groupStmt->execute($params);
    $grouped = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $grouped = [];
}

// Detail records
$detailStmt = $pdo->prepare("
    SELECT e.*, v.name as vendor_name, ec.name as category_name, c.name as client_name
    FROM expenses e
    LEFT JOIN vendors v ON v.id = e.vendor_id
    LEFT JOIN expense_categories ec ON ec.id = e.category_id
    LEFT JOIN clients c ON c.id = e.client_id
    WHERE {$whereSQL}
    ORDER BY e.expense_date DESC
");
$detailStmt->execute($params);
$details = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

// Build export URL with current filters
$exportParams = http_build_query(array_filter([
    'start' => $start, 'end' => $end, 'category_id' => $categoryId,
    'vendor_id' => $vendorId, 'client_id' => $clientId,
    'billable' => $billable, 'tax_deductible' => $taxDeductible, 'status' => $status,
]));
?>

<div style="max-width:1400px;margin:0 auto;padding:24px">
  <div class="page-head">
    <h2>Expense Report</h2>
    <a href="/?page=financial/expense-export&<?php echo htmlspecialchars($exportParams); ?>" class="btn btn-sm">Export CSV</a>
  </div>

  <!-- Filters -->
  <div class="card" style="margin-bottom:20px">
    <form method="get" action="/" class="grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
      <input type="hidden" name="page" value="financial/expense-report">
      <div class="field">
        <label class="label-muted">Start Date</label>
        <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" class="input">
      </div>
      <div class="field">
        <label class="label-muted">End Date</label>
        <input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" class="input">
      </div>
      <div class="field">
        <label class="label-muted">Category</label>
        <select name="category_id" class="input">
          <option value="0">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?php echo (int)$cat['id']; ?>" <?php echo $categoryId === (int)$cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="label-muted">Vendor</label>
        <select name="vendor_id" class="input">
          <option value="0">All Vendors</option>
          <?php foreach ($vendors as $v): ?>
            <option value="<?php echo (int)$v['id']; ?>" <?php echo $vendorId === (int)$v['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($v['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="label-muted">Client</label>
        <select name="client_id" class="input">
          <option value="0">All Clients</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php echo $clientId === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="label-muted">Billable</label>
        <select name="billable" class="input">
          <option value="">All</option>
          <option value="1" <?php echo $billable === '1' ? 'selected' : ''; ?>>Billable Only</option>
          <option value="0" <?php echo $billable === '0' ? 'selected' : ''; ?>>Non-Billable Only</option>
        </select>
      </div>
      <div class="field">
        <label class="label-muted">Tax Deductible</label>
        <select name="tax_deductible" class="input">
          <option value="">All</option>
          <option value="1" <?php echo $taxDeductible === '1' ? 'selected' : ''; ?>>Deductible Only</option>
          <option value="0" <?php echo $taxDeductible === '0' ? 'selected' : ''; ?>>Non-Deductible Only</option>
        </select>
      </div>
      <div class="field">
        <label class="label-muted">Group By</label>
        <select name="group_by" class="input">
          <option value="none" <?php echo $groupBy === 'none' ? 'selected' : ''; ?>>No Grouping</option>
          <option value="category" <?php echo $groupBy === 'category' ? 'selected' : ''; ?>>By Category</option>
          <option value="vendor" <?php echo $groupBy === 'vendor' ? 'selected' : ''; ?>>By Vendor</option>
          <option value="month" <?php echo $groupBy === 'month' ? 'selected' : ''; ?>>By Month</option>
        </select>
      </div>
      <div style="display:flex;align-items:end">
        <button type="submit" class="btn btn-primary">Apply Filters</button>
      </div>
    </form>
  </div>

  <!-- Summary Cards -->
  <div class="grid grid-4" style="margin-bottom:20px">
    <div class="card card-tight">
      <div class="muted text-sm">Total Expenses</div>
      <div class="font-600" style="font-size:20px"><?php echo money_format_total($summary['grand_total']); ?></div>
      <div class="muted-note"><?php echo (int)$summary['count']; ?> expenses</div>
    </div>
    <div class="card card-tight">
      <div class="muted text-sm">Tax Amount</div>
      <div class="font-600" style="font-size:20px"><?php echo money_format_total($summary['total_tax']); ?></div>
    </div>
    <div class="card card-tight">
      <div class="muted text-sm">Billable</div>
      <div class="font-600" style="font-size:20px"><?php echo money_format_total($summary['billable_total']); ?></div>
    </div>
    <div class="card card-tight">
      <div class="muted text-sm">Tax-Deductible</div>
      <div class="font-600" style="font-size:20px"><?php echo money_format_total($summary['deductible_total']); ?></div>
    </div>
  </div>

  <?php if (!empty($grouped)): ?>
  <!-- Grouped Summary -->
  <div class="card" style="margin-bottom:20px">
    <h3 class="card-title" style="margin-bottom:12px">Summary by <?php echo htmlspecialchars($groupBy); ?></h3>
    <table class="pa-table">
      <thead>
        <tr>
          <th><?php echo ucfirst($groupBy); ?></th>
          <th class="text-right">Count</th>
          <th class="text-right">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($grouped as $g): ?>
          <tr>
            <td><?php echo htmlspecialchars($g['group_name']); ?></td>
            <td class="text-right"><?php echo (int)$g['count']; ?></td>
            <td class="text-right font-600"><?php echo htmlspecialchars(money_format_total($g['total'])); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Detail Table -->
  <div class="card">
    <h3 class="card-title" style="margin-bottom:12px">Expense Details</h3>
    <?php if (empty($details)): ?>
      <p class="muted" style="text-align:center;padding:32px">No expenses found for the selected filters.</p>
    <?php else: ?>
    <div class="pa-table-wrap">
      <table class="pa-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Vendor</th>
            <th>Description</th>
            <th>Category</th>
            <th class="text-right">Amount</th>
            <th class="text-right">Tax</th>
            <th class="text-right">Total</th>
            <th>Payment</th>
            <th>Billable</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($details as $d): ?>
            <tr>
              <td><?php echo htmlspecialchars($d['expense_date']); ?></td>
              <td><?php echo htmlspecialchars($d['vendor_name'] ?? '—'); ?></td>
              <td><?php echo htmlspecialchars(mb_substr($d['description'] ?? '', 0, 40)); ?></td>
              <td><?php echo htmlspecialchars($d['category_name'] ?? '—'); ?></td>
              <td class="text-right"><?php echo htmlspecialchars(money_format_total($d['amount'])); ?></td>
              <td class="text-right"><?php echo $d['tax_amount'] ? htmlspecialchars(money_format_total($d['tax_amount'])) : '—'; ?></td>
              <td class="text-right font-600"><?php echo htmlspecialchars(money_format_total($d['total_amount'] ?? $d['amount'])); ?></td>
              <td><?php echo htmlspecialchars($d['payment_method'] ?? '—'); ?></td>
              <td><?php echo $d['is_billable'] ? '<span class="status-pill status-pill--active">Billable</span>' : '<span class="muted">—</span>'; ?></td>
              <td><span class="status-pill status-pill--<?php echo htmlspecialchars(strtolower($d['status'])); ?>"><?php echo htmlspecialchars($d['status']); ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
function money_format_total($val) {
    return '$' . number_format((float)$val, 2);
}
?>