<?php
// src/views/pages/financial/financial-dashboard.php
require_once __DIR__ . '/../../../config/db.php';

// Date range filter (default: current year Jan 1 - Dec 31)
$defaultStartDate = date('Y') . '-01-01';
$defaultEndDate = date('Y') . '-12-31';
$start = !empty($_GET['start']) ? $_GET['start'] : $defaultStartDate;
$end = !empty($_GET['end']) ? $_GET['end'] : $defaultEndDate;

// Ensure valid date strings; fall back to defaults on bad input
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
    $start = $defaultStartDate;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    $end = $defaultEndDate;
}

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

$mileageStmt = $pdo->prepare("SELECT COALESCE(SUM(miles * mileage_rate),0) as total, COALESCE(SUM(miles),0) as miles FROM mileage_logs WHERE organization_id=1 AND purpose='business' AND trip_date BETWEEN ? AND ?");
$mileageStmt->execute([$start, $end]);
$mileageSummary = $mileageStmt->fetch(PDO::FETCH_ASSOC);
$totalMileageDeduction = (float)$mileageSummary['total'];
$totalMiles = (float)$mileageSummary['miles'];

// Expense breakdown by category
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
foreach ($categories as $c) {
    $categoryMax = max($categoryMax, (float)$c['total']);
}

// Top vendors
$vendorStmt = $pdo->prepare("
    SELECT v.name, COALESCE(SUM(e.total_amount),0) as total, COUNT(e.id) as count
    FROM vendors v
    LEFT JOIN expenses e ON e.vendor_id = v.id AND e.organization_id = 1 AND e.status != 'void' AND e.expense_date BETWEEN ? AND ?
    WHERE v.organization_id = 1 AND v.is_active = 1
    GROUP BY v.id
    HAVING total > 0
    ORDER BY total DESC
    LIMIT 10
");
$vendorStmt->execute([$start, $end]);
$vendors = $vendorStmt->fetchAll(PDO::FETCH_ASSOC);

// Monthly trend (income vs expenses)
$periodStart = new DateTime($start . ' first day of this month');
$periodEnd = new DateTime($end . ' first day of this month');
$months = [];
$current = clone $periodStart;
while ($current <= $periodEnd) {
    $months[] = $current->format('Y-m');
    $current->modify('+1 month');
}

$monthlyIncome = [];
$monthlyExpenses = [];
if (!empty($months)) {
    $placeholders = implode(',', array_fill(0, count($months), '?'));

    $incomeMonthStmt = $pdo->prepare("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, COALESCE(SUM(amount),0) as total
        FROM payments
        WHERE status='succeeded' AND payment_date BETWEEN ? AND ? AND DATE_FORMAT(payment_date, '%Y-%m') IN ($placeholders)
        GROUP BY month
    ");
    $incomeMonthStmt->execute(array_merge([$start, $end], $months));
    $monthlyIncome = $incomeMonthStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $expenseMonthStmt = $pdo->prepare("
        SELECT DATE_FORMAT(expense_date, '%Y-%m') as month, COALESCE(SUM(total_amount),0) as total
        FROM expenses
        WHERE organization_id=1 AND status != 'void' AND expense_date BETWEEN ? AND ? AND DATE_FORMAT(expense_date, '%Y-%m') IN ($placeholders)
        GROUP BY month
    ");
    $expenseMonthStmt->execute(array_merge([$start, $end], $months));
    $monthlyExpenses = $expenseMonthStmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Expense summary table by category
$summaryStmt = $pdo->prepare("
    SELECT
        ec.name,
        COUNT(e.id) as count,
        COALESCE(SUM(e.total_amount),0) as total,
        COALESCE(SUM(CASE WHEN e.is_tax_deductible = 1 THEN e.total_amount ELSE 0 END),0) as tax_deductible,
        COALESCE(SUM(CASE WHEN e.is_billable = 1 THEN e.total_amount ELSE 0 END),0) as billable
    FROM expense_categories ec
    LEFT JOIN expenses e ON e.category_id = ec.id AND e.organization_id = 1 AND e.status != 'void' AND e.expense_date BETWEEN ? AND ?
    WHERE ec.organization_id = 1
    GROUP BY ec.id
    HAVING total > 0
    ORDER BY total DESC
");
$summaryStmt->execute([$start, $end]);
$summaryRows = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

$summaryTotals = [
    'count' => 0,
    'total' => 0.0,
    'tax_deductible' => 0.0,
    'billable' => 0.0,
];
foreach ($summaryRows as $row) {
    $summaryTotals['count'] += (int)$row['count'];
    $summaryTotals['total'] += (float)$row['total'];
    $summaryTotals['tax_deductible'] += (float)$row['tax_deductible'];
    $summaryTotals['billable'] += (float)$row['billable'];
}

function formatMoney(float $amount): string {
    return '$' . number_format($amount, 2);
}

$netClass = $netProfit >= 0 ? 'badge-green' : 'badge-red';
?>
<section>
  <div class="page-head">
    <h2>Financial Dashboard</h2>
  </div>

  <!-- Date Range Filter -->
  <div class="dash-panel">
    <div class="dash-panel__head">
      <h3 class="dash-panel__title">Date Range</h3>
    </div>
    <form method="get" action="/" class="flex flex-wrap gap-16">
      <input type="hidden" name="page" value="financial/financial-dashboard">
      <label>
        <div class="label">Start Date</div>
        <input type="date" name="start" class="input" value="<?php echo htmlspecialchars($start); ?>">
      </label>
      <label>
        <div class="label">End Date</div>
        <input type="date" name="end" class="input" value="<?php echo htmlspecialchars($end); ?>">
      </label>
      <div class="flex gap-16">
        <button type="submit" class="btn btn-primary">Apply</button>
        <a href="/?page=financial/financial-dashboard" class="btn btn-secondary">Reset</a>
      </div>
    </form>
  </div>

  <!-- Summary Cards -->
  <div class="dash-stats mt-24">
    <article class="dash-card dash-card--income">
      <div class="dash-card__icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="12" y1="1" x2="12" y2="23"></line>
          <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
        </svg>
      </div>
      <div>
        <div class="dash-card__label">Total Income</div>
        <div class="dash-card__value"><?php echo formatMoney($totalIncome); ?></div>
      </div>
    </article>

    <article class="dash-card dash-card--unpaid">
      <div class="dash-card__icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
          <line x1="1" y1="10" x2="23" y2="10"></line>
        </svg>
      </div>
      <div>
        <div class="dash-card__label">Total Expenses</div>
        <div class="dash-card__value"><?php echo formatMoney($totalExpenses); ?></div>
      </div>
    </article>

    <article class="dash-card dash-card--active">
      <div class="dash-card__icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 7h-9"></path>
          <path d="M14 17H5"></path>
          <circle cx="17" cy="17" r="3"></circle>
          <circle cx="7" cy="7" r="3"></circle>
        </svg>
      </div>
      <div>
        <div class="dash-card__label">Net Profit</div>
        <div class="dash-card__value <?php echo $netClass; ?>"><?php echo formatMoney($netProfit); ?></div>
      </div>
    </article>

    <article class="dash-card dash-card--clients">
      <div class="dash-card__icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M19 17h2c.6 0 1-.4 1-1v-4c0-.6-.4-1-1-1h-2"></path>
          <circle cx="9" cy="9" r="4"></circle>
          <path d="M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"></path>
        </svg>
      </div>
      <div>
        <div class="dash-card__label">Mileage Deductions</div>
        <div class="dash-card__value"><?php echo formatMoney($totalMileageDeduction); ?></div>
        <div class="text-sm muted"><?php echo number_format($totalMiles, 1); ?> mi</div>
      </div>
    </article>
  </div>

  <!-- Two-column layout -->
  <div class="dash-cols">
    <!-- Left column -->
    <div class="dash-col">
      <!-- Expense Breakdown by Category -->
      <div class="dash-panel">
        <div class="dash-panel__head">
          <h3 class="dash-panel__title">Expense Breakdown by Category</h3>
        </div>
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
                <div class="dash-bar__track">
                  <div class="dash-bar__fill" style="width:<?php echo $barWidth; ?>%;background:<?php echo $barColor; ?>"></div>
                </div>
                <div class="dash-bar__count"><?php echo formatMoney($catTotal); ?> (<?php echo $catPercent; ?>%)</div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Monthly Income vs Expense Trend -->
      <div class="dash-panel">
        <div class="dash-panel__head">
          <h3 class="dash-panel__title">Monthly Income vs Expense</h3>
        </div>
        <?php if (empty($months)): ?>
          <p class="dash-empty">No months in the selected range.</p>
        <?php else: ?>
          <div class="pa-table-wrap">
            <table class="pa-table">
              <thead>
                <tr>
                  <th>Month</th>
                  <th class="text-right">Income</th>
                  <th class="text-right">Expenses</th>
                  <th class="text-right">Net</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($months as $month):
                  $mIncome = (float)($monthlyIncome[$month] ?? 0.0);
                  $mExpenses = (float)($monthlyExpenses[$month] ?? 0.0);
                  $mNet = $mIncome - $mExpenses;
                  $mLabel = date('M Y', strtotime($month . '-01'));
                ?>
                  <tr>
                    <td><?php echo htmlspecialchars($mLabel); ?></td>
                    <td class="text-right"><?php echo formatMoney($mIncome); ?></td>
                    <td class="text-right"><?php echo formatMoney($mExpenses); ?></td>
                    <td class="text-right <?php echo $mNet >= 0 ? 'badge-green' : 'badge-red'; ?>"><?php echo formatMoney($mNet); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- Expense Summary Table -->
      <div class="dash-panel">
        <div class="dash-panel__head">
          <h3 class="dash-panel__title">Expense Summary</h3>
        </div>
        <?php if (empty($summaryRows)): ?>
          <p class="dash-empty">No expenses for the selected period.</p>
        <?php else: ?>
          <div class="pa-table-wrap">
            <table class="pa-table">
              <thead>
                <tr>
                  <th>Category</th>
                  <th class="text-right">Count</th>
                  <th class="text-right">Total Amount</th>
                  <th class="text-right">Tax-Deductible</th>
                  <th class="text-right">Billable</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($summaryRows as $row): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                    <td class="text-right"><?php echo number_format((int)$row['count']); ?></td>
                    <td class="text-right"><?php echo formatMoney((float)$row['total']); ?></td>
                    <td class="text-right"><?php echo formatMoney((float)$row['tax_deductible']); ?></td>
                    <td class="text-right"><?php echo formatMoney((float)$row['billable']); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr style="font-weight:700;background:var(--surface-2)">
                  <td>Total</td>
                  <td class="text-right"><?php echo number_format($summaryTotals['count']); ?></td>
                  <td class="text-right"><?php echo formatMoney($summaryTotals['total']); ?></td>
                  <td class="text-right"><?php echo formatMoney($summaryTotals['tax_deductible']); ?></td>
                  <td class="text-right"><?php echo formatMoney($summaryTotals['billable']); ?></td>
                </tr>
              </tfoot>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right column -->
    <div class="dash-col">
      <!-- Top Vendors by Spend -->
      <div class="dash-panel">
        <div class="dash-panel__head">
          <h3 class="dash-panel__title">Top Vendors by Spend</h3>
        </div>
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
