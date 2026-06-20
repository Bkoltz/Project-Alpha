<?php
// src/views/pages/financial/financial-dashboard.php
require_once __DIR__ . '/../../../config/db.php';

// Date range filter (default: current year)
$defaultStartDate = date('Y') . '-01-01';
$defaultEndDate = date('Y') . '-12-31';
$start = !empty($_GET['start']) ? $_GET['start'] : $defaultStartDate;
$end = !empty($_GET['end']) ? $_GET['end'] : $defaultEndDate;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = $defaultStartDate;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = $defaultEndDate;

// Summary queries
$incomeStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE status='succeeded' AND payment_date BETWEEN ? AND ?");
$incomeStmt->execute([$start, $end]);
$totalIncome = (float)$incomeStmt->fetchColumn();

$expenseStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) as total, COUNT(*) as count FROM expenses WHERE organization_id=1 AND status != 'void' AND expense_date BETWEEN ? AND ?");
$expenseStmt->execute([$start, $end]);
$expenseSummary = $expenseStmt->fetch(PDO::FETCH_ASSOC);
$totalExpenses = (float)$expenseSummary['total'];
$expenseCount = (int)$expenseSummary['count'];

$netProfit = $totalIncome - $totalExpenses;

$mileageStmt = $pdo->prepare("SELECT COALESCE(SUM(miles * mileage_rate),0) as total, COALESCE(SUM(miles),0) as miles, COUNT(*) as trips FROM mileage_logs WHERE organization_id=1 AND purpose='business' AND trip_date BETWEEN ? AND ?");
$mileageStmt->execute([$start, $end]);
$mileageSummary = $mileageStmt->fetch(PDO::FETCH_ASSOC);
$totalMileageDeduction = (float)($mileageSummary['total'] ?? 0);
$totalMiles = (float)($mileageSummary['miles'] ?? 0);
$totalTrips = (int)($mileageSummary['trips'] ?? 0);

$receiptStmt = $pdo->prepare("SELECT COUNT(*) as count FROM receipts WHERE organization_id=1 AND created_at BETWEEN ? AND ?");
$receiptStmt->execute([$start . ' 00:00:00', $end . ' 23:59:59']);
$receiptCount = (int)$receiptStmt->fetchColumn();

// Category breakdown
$catStmt = $pdo->prepare("
    SELECT ec.name, ec.color, COALESCE(SUM(e.total_amount),0) as total, COUNT(e.id) as count
    FROM expense_categories ec
    LEFT JOIN expenses e ON e.category_id = ec.id AND e.organization_id = 1 AND e.status != 'void' AND e.expense_date BETWEEN ? AND ?
    WHERE ec.organization_id = 1
    GROUP BY ec.id
    HAVING total > 0
    ORDER BY total DESC
");
$catStmt->execute([$start, $end]);
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
$categoryMax = 0;
foreach ($categories as $c) $categoryMax = max($categoryMax, (float)$c['total']);

// Top vendors
$vendorStmt = $pdo->prepare("
    SELECT v.name, COALESCE(SUM(e.total_amount),0) as total, COUNT(e.id) as count
    FROM vendors v
    LEFT JOIN expenses e ON e.vendor_id = v.id AND e.organization_id = 1 AND e.status != 'void' AND e.expense_date BETWEEN ? AND ?
    WHERE v.organization_id = 1 AND v.is_active = 1
    GROUP BY v.id
    HAVING total > 0
    ORDER BY total DESC
    LIMIT 8
");
$vendorStmt->execute([$start, $end]);
$vendors = $vendorStmt->fetchAll(PDO::FETCH_ASSOC);

// Recent expenses
$recentStmt = $pdo->prepare("
    SELECT e.id, e.expense_date, e.total_amount, e.description, e.status, ec.name as category, v.name as vendor
    FROM expenses e
    LEFT JOIN expense_categories ec ON ec.id = e.category_id
    LEFT JOIN vendors v ON v.id = e.vendor_id
    WHERE e.organization_id = 1 AND e.status != 'void' AND e.expense_date BETWEEN ? AND ?
    ORDER BY e.expense_date DESC, e.id DESC
    LIMIT 10
");
$recentStmt->execute([$start, $end]);
$recentExpenses = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

function formatMoney(float $amount): string { return '$' . number_format($amount, 2); }
function formatDate(?string $d): string { return $d ? date('M j, Y', strtotime($d)) : '—'; }

$netClass = $netProfit >= 0 ? 'success' : 'danger';
?>

<section class="finance-dashboard">
  <div class="page-head">
    <h2>Financial Dashboard</h2>
  </div>

  <!-- Date filter + quick actions -->
  <div class="dash-row">
    <div class="dash-card dash-card--filter">
      <form method="get" action="/" class="filter-form compact">
        <input type="hidden" name="page" value="financial/financial-dashboard">
        <label><span class="label">Start</span><input type="date" name="start" class="input" value="<?php echo htmlspecialchars($start); ?>"></label>
        <label><span class="label">End</span><input type="date" name="end" class="input" value="<?php echo htmlspecialchars($end); ?>"></label>
        <div class="filter-actions">
          <button type="submit" class="btn btn-primary">Apply</button>
          <a href="/?page=financial/financial-dashboard" class="btn btn-secondary">Reset</a>
        </div>
      </form>
    </div>
    <div class="dash-card dash-card--actions">
      <div class="quick-actions">
        <a href="/?page=financial/expenses-list" class="btn btn-primary">Expenses Hub</a>
        <a href="/?page=financial/expense-create" class="btn btn-secondary">Add Expense</a>
        <a href="/?page=financial/expense-report" class="btn btn-secondary">Reports</a>
        <a href="/?page=financial/forms-list" class="btn btn-secondary">Forms &amp; Docs</a>
      </div>
    </div>
  </div>

  <!-- KPI cards -->
  <div class="dash-stats">
    <article class="dash-card">
      <div class="dash-card__icon success"><!-- income arrow -->
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
      </div>
      <div>
        <div class="dash-card__label">Total Income</div>
        <div class="dash-card__value"><?php echo formatMoney($totalIncome); ?></div>
      </div>
    </article>

    <article class="dash-card">
      <div class="dash-card__icon danger">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
      </div>
      <div>
        <div class="dash-card__label">Total Expenses</div>
        <div class="dash-card__value"><?php echo formatMoney($totalExpenses); ?></div>
        <div class="dash-card__meta"><?php echo number_format($expenseCount); ?> expense<?php echo $expenseCount === 1 ? '' : 's'; ?></div>
      </div>
    </article>

    <article class="dash-card">
      <div class="dash-card__icon <?php echo $netClass; ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7h-9"></path><path d="M14 17H5"></path><circle cx="17" cy="17" r="3"></circle><circle cx="7" cy="7" r="3"></circle></svg>
      </div>
      <div>
        <div class="dash-card__label">Net Profit</div>
        <div class="dash-card__value <?php echo $netClass; ?>"><?php echo formatMoney($netProfit); ?></div>
      </div>
    </article>

    <article class="dash-card">
      <div class="dash-card__icon info">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2c.6 0 1-.4 1-1v-4c0-.6-.4-1-1-1h-2"></path><circle cx="9" cy="9" r="4"></circle><path d="M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"></path></svg>
      </div>
      <div>
        <div class="dash-card__label">Mileage Deductions</div>
        <div class="dash-card__value"><?php echo formatMoney($totalMileageDeduction); ?></div>
        <div class="dash-card__meta"><?php echo number_format($totalMiles, 1); ?> mi · <?php echo number_format($totalTrips); ?> trip<?php echo $totalTrips === 1 ? '' : 's'; ?></div>
      </div>
    </article>

    <article class="dash-card">
      <div class="dash-card__icon warning">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="8" y1="12" x2="16" y2="12"></line><line x1="12" y1="8" x2="12" y2="16"></line></svg>
      </div>
      <div>
        <div class="dash-card__label">Receipts</div>
        <div class="dash-card__value"><?php echo number_format($receiptCount); ?></div>
      </div>
    </article>
  </div>

  <!-- Two-column details -->
  <div class="dash-cols">
    <div class="dash-col dash-col--wide">
      <!-- Category breakdown -->
      <div class="dash-panel">
        <div class="dash-panel__head"><h3 class="dash-panel__title">Spending by Category</h3><a href="/?page=financial/expenses-list&tab=categories" class="btn btn-sm">Manage Categories</a></div>
        <?php if (empty($categories)): ?>
          <p class="dash-empty">No expenses for the selected period.</p>
        <?php else: ?>
          <div class="dash-bars">
            <?php foreach ($categories as $c): 
              $catTotal = (float)$c['total'];
              $catPercent = $totalExpenses > 0 ? round(($catTotal / $totalExpenses) * 100, 1) : 0;
              $barWidth = $categoryMax > 0 ? round(($catTotal / $categoryMax) * 100) : 0;
              $barColor = !empty($c['color']) ? htmlspecialchars($c['color']) : 'var(--nav-accent)';
            ?>
              <div class="dash-bar__row">
                <div class="dash-bar__label" title="<?php echo htmlspecialchars($c['name']); ?>"><?php echo htmlspecialchars($c['name']); ?></div>
                <div class="dash-bar__track"><div class="dash-bar__fill" style="width:<?php echo $barWidth; ?>%;background:<?php echo $barColor; ?>"></div></div>
                <div class="dash-bar__count"><?php echo formatMoney($catTotal); ?> <span>(<?php echo $catPercent; ?>%)</span></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Recent expenses -->
      <div class="dash-panel">
        <div class="dash-panel__head"><h3 class="dash-panel__title">Recent Expenses</h3><a href="/?page=financial/expenses-list" class="btn btn-sm">View All</a></div>
        <?php if (empty($recentExpenses)): ?>
          <p class="dash-empty">No expenses for the selected period.</p>
        <?php else: ?>
          <div class="pa-table-wrap">
            <table class="pa-table">
              <thead><tr><th>Date</th><th>Vendor / Description</th><th>Category</th><th class="text-right">Amount</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($recentExpenses as $e): ?>
                  <tr>
                    <td><?php echo formatDate($e['expense_date']); ?></td>
                    <td><strong><?php echo htmlspecialchars($e['vendor'] ?: '—'); ?></strong><div class="muted small"><?php echo htmlspecialchars(mb_strimwidth($e['description'] ?? '', 0, 60, '…')); ?></div></td>
                    <td><?php echo htmlspecialchars($e['category'] ?: 'Uncategorized'); ?></td>
                    <td class="text-right"><?php echo formatMoney((float)$e['total_amount']); ?></td>
                    <td><span class="status-badge status-<?php echo htmlspecialchars($e['status']); ?>"><?php echo htmlspecialchars(ucfirst($e['status'])); ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="dash-col">
      <!-- Top vendors -->
      <div class="dash-panel">
        <div class="dash-panel__head"><h3 class="dash-panel__title">Top Vendors</h3><a href="/?page=financial/expenses-list&tab=vendors" class="btn btn-sm">Manage</a></div>
        <?php if (empty($vendors)): ?>
          <p class="dash-empty">No vendor spending for the selected period.</p>
        <?php else: ?>
          <div class="dash-list">
            <?php foreach ($vendors as $v): ?>
              <div class="dash-list__item">
                <div class="dash-list__left">
                  <div class="dash-list__title"><?php echo htmlspecialchars($v['name']); ?></div>
                  <div class="dash-list__meta"><?php echo number_format((int)$v['count']); ?> expense<?php echo (int)$v['count'] === 1 ? '' : 's'; ?></div>
                </div>
                <div class="dash-list__time"><?php echo formatMoney((float)$v['total']); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
