<?php
// src/views/pages/financial/expenses-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/twig.php';

$orgId = 1;

// Filters
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$categoryId = (int)($_GET['category_id'] ?? 0);
$vendorId = (int)($_GET['vendor_id'] ?? 0);
$clientId = (int)($_GET['client_id'] ?? 0);
$billable = $_GET['billable'] ?? '';
$status = $_GET['status'] ?? '';

$where = ['e.organization_id = ?'];
$params = [$orgId];

if ($start) { $where[] = 'e.expense_date >= ?'; $params[] = $start; }
if ($end) { $where[] = 'e.expense_date <= ?'; $params[] = $end; }
if ($categoryId > 0) { $where[] = 'e.category_id = ?'; $params[] = $categoryId; }
if ($vendorId > 0) { $where[] = 'e.vendor_id = ?'; $params[] = $vendorId; }
if ($clientId > 0) { $where[] = 'e.client_id = ?'; $params[] = $clientId; }
if ($billable === '1') { $where[] = 'e.is_billable = 1'; }
if ($billable === '0') { $where[] = 'e.is_billable = 0'; }
if ($status) { $where[] = 'e.status = ?'; $params[] = $status; }

$whereSQL = implode(' AND ', $where);

// Pagination
$per = (int)($_GET['per_page'] ?? 50);
if (!in_array($per, [50, 100], true)) $per = 50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;

// Count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses e WHERE {$whereSQL}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// Fetch expenses
$sql = "
    SELECT e.*, v.name as vendor_name, ec.name as category_name, c.name as client_name
    FROM expenses e
    LEFT JOIN vendors v ON v.id = e.vendor_id
    LEFT JOIN expense_categories ec ON ec.id = e.category_id
    LEFT JOIN clients c ON c.id = e.client_id
    WHERE {$whereSQL}
    ORDER BY e.expense_date DESC, e.created_at DESC
    LIMIT $per OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary
$sumStmt = $pdo->prepare("
    SELECT COALESCE(SUM(e.total_amount),0) as grand_total,
           COALESCE(SUM(CASE WHEN e.is_billable=1 THEN e.total_amount ELSE 0 END),0) as billable_total,
           COALESCE(SUM(CASE WHEN e.is_tax_deductible=1 THEN e.total_amount ELSE 0 END),0) as deductible_total
    FROM expenses e WHERE {$whereSQL}
");
$sumStmt->execute($params);
$summary = $sumStmt->fetch(PDO::FETCH_ASSOC);

// Fetch filter options
$cats = $pdo->prepare('SELECT id, name FROM expense_categories WHERE organization_id=? ORDER BY name');
$cats->execute([$orgId]);
$categories = $cats->fetchAll(PDO::FETCH_ASSOC);

$vendorsQ = $pdo->prepare('SELECT id, name FROM vendors WHERE organization_id=? AND is_active=1 ORDER BY name');
$vendorsQ->execute([$orgId]);
$vendors = $vendorsQ->fetchAll(PDO::FETCH_ASSOC);

$clientsQ = $pdo->query('SELECT id, name FROM clients ORDER BY name');
$clients = $clientsQ->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, ceil($total / $per));
?>

<div style="max-width:1400px;margin:0 auto;padding:24px">
        <div class="page-head">
          <h2 style="margin:0">Expenses</h2>
          <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="/?page=financial/expense-report" class="btn btn-sm">Reports</a>
            <a href="/?page=financial/csv-import" class="btn btn-sm">Import CSV</a>
            <a href="/?page=financial/expense-create" class="btn btn-primary">Add Expense</a>
          </div>
        </div>

  <?php if (!empty($_GET['created'])): ?>
    <div class="alert alert-success">Expense created successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['updated'])): ?>
    <div class="alert alert-success">Expense updated successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['deleted'])): ?>
    <div class="alert alert-success">Expense deleted.</div>
  <?php endif; ?>

  <!-- Summary Cards -->
  <div class="grid grid-3" style="margin-bottom:20px">
    <div class="card card-tight">
      <div class="muted text-sm">Total Expenses</div>
      <div class="font-600" style="font-size:20px">$<?php echo number_format((float)$summary['grand_total'], 2); ?></div>
    </div>
    <div class="card card-tight">
      <div class="muted text-sm">Billable</div>
      <div class="font-600" style="font-size:20px">$<?php echo number_format((float)$summary['billable_total'], 2); ?></div>
    </div>
    <div class="card card-tight">
      <div class="muted text-sm">Tax-Deductible</div>
      <div class="font-600" style="font-size:20px">$<?php echo number_format((float)$summary['deductible_total'], 2); ?></div>
    </div>
  </div>

  <!-- Filters -->
  <div class="card" style="margin-bottom:20px">
    <form method="get" action="/" class="filter-form">
      <input type="hidden" name="page" value="financial/expenses-list">
      <div class="field"><label class="label-muted">Start</label><input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" class="input"></div>
      <div class="field"><label class="label-muted">End</label><input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" class="input"></div>
      <div class="field">
        <label class="label-muted">Category</label>
        <select name="category_id" class="input"><option value="0">All</option>
          <?php foreach ($categories as $cat): ?><option value="<?php echo (int)$cat['id']; ?>" <?php echo $categoryId===(int)$cat['id']?'selected':''; ?>><?php echo htmlspecialchars($cat['name']); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="label-muted">Vendor</label>
        <select name="vendor_id" class="input"><option value="0">All</option>
          <?php foreach ($vendors as $v): ?><option value="<?php echo (int)$v['id']; ?>" <?php echo $vendorId===(int)$v['id']?'selected':''; ?>><?php echo htmlspecialchars($v['name']); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="label-muted">Billable</label>
        <select name="billable" class="input"><option value="">All</option><option value="1" <?php echo $billable==='1'?'selected':''; ?>>Billable</option><option value="0" <?php echo $billable==='0'?'selected':''; ?>>Non-Billable</option></select>
      </div>
      <div class="field">
        <label class="label-muted">Status</label>
        <select name="status" class="input"><option value="">All</option><option value="confirmed" <?php echo $status==='confirmed'?'selected':''; ?>>Confirmed</option><option value="pending" <?php echo $status==='pending'?'selected':''; ?>>Pending</option><option value="reimbursed" <?php echo $status==='reimbursed'?'selected':''; ?>>Reimbursed</option><option value="void" <?php echo $status==='void'?'selected':''; ?>>Void</option></select>
      </div>
      <div class="field filter-actions"><button type="submit" class="btn btn-primary">Filter</button></div>
    </form>
  </div>

  <!-- Expense Table -->
  <?php if (empty($expenses)): ?>
    <div class="card" style="text-align:center;padding:48px">
      <p class="muted" style="font-size:16px;margin-bottom:16px">No expenses found.</p>
      <a href="/?page=financial/expense-create" class="btn btn-primary">Add Your First Expense</a>
    </div>
  <?php else: ?>
    <div class="pa-table-wrap">
      <table class="pa-table">
        <thead>
          <tr>
            <th>Date</th><th>Vendor</th><th>Description</th><th>Category</th>
            <th class="text-right">Amount</th><th class="text-right">Total</th>
            <th>Billable</th><th>Status</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($expenses as $e): ?>
          <tr>
            <td><?php echo htmlspecialchars($e['expense_date']); ?></td>
            <td><?php echo htmlspecialchars($e['vendor_name'] ?? '—'); ?></td>
            <td><?php echo htmlspecialchars(mb_substr($e['description'] ?? '', 0, 40)); ?></td>
            <td><?php echo htmlspecialchars($e['category_name'] ?? '—'); ?></td>
            <td class="text-right">$<?php echo number_format((float)$e['amount'], 2); ?></td>
            <td class="text-right font-600">$<?php echo number_format((float)($e['total_amount'] ?? $e['amount']), 2); ?></td>
            <td><?php echo $e['is_billable'] ? '<span class="status-pill status-pill--active">Yes</span>' : '<span class="muted">—</span>'; ?></td>
            <td><span class="status-pill status-pill--<?php echo htmlspecialchars(strtolower($e['status'])); ?>"><?php echo htmlspecialchars($e['status']); ?></span></td>
            <td>
              <a href="/?page=financial/expense-detail&id=<?php echo (int)$e['id']; ?>" class="btn btn-sm">View</a>
              <a href="/?page=financial/expense-create&id=<?php echo (int)$e['id']; ?>" class="btn btn-sm">Edit</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="flex" style="margin-top:16px;justify-content:center">
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="/?page=financial/expenses-list&p=<?php echo $i; ?>&<?php echo http_build_query(array_filter(['start'=>$start,'end'=>$end,'category_id'=>$categoryId,'vendor_id'=>$vendorId,'billable'=>$billable,'status'=>$status])); ?>"
           class="btn btn-sm" <?php echo $i === $pageN ? 'style="background:var(--nav-accent);color:#fff"' : ''; ?>><?php echo $i; ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>