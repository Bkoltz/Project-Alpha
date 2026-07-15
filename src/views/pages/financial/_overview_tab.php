<?php
// Assets & Expenses landing overview. Expense totals include generated recurring
// expenses; receipt files and recurring schedules are supporting expense data.
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/financial_assets.php';
require_once __DIR__ . '/../../../utils/recurring_expenses.php';

$overview = [
    'asset_count' => 0,
    'asset_purchase_cost' => 0.0,
    'asset_book_value' => 0.0,
    'expense_count' => 0,
    'expense_total' => 0.0,
    'expense_ytd' => 0.0,
    'recurring_count' => 0,
    'recurring_annual' => 0.0,
    'receipt_count' => 0,
    'receipt_unmatched' => 0,
    'mileage_trips' => 0,
    'mileage_miles' => 0.0,
    'mileage_amount' => 0.0,
    'mileage_unbilled' => 0.0,
];

try {
    [$overviewAssetWhere, $overviewAssetParams] = finance_scope_clause($pdo, 'a', $userId, $orgId, 'created_by');
    $overviewStmt = $pdo->prepare("SELECT * FROM financial_assets a WHERE {$overviewAssetWhere} AND a.status NOT IN ('disposed','lost')");
    $overviewStmt->execute($overviewAssetParams);
    foreach ($overviewStmt->fetchAll(PDO::FETCH_ASSOC) as $overviewAsset) {
        $overview['asset_count']++;
        $overview['asset_purchase_cost'] += (float)($overviewAsset['purchase_cost'] ?? 0);
        $overview['asset_book_value'] += financial_asset_depreciation($overviewAsset)['book_value'];
    }

    [$overviewExpenseWhere, $overviewExpenseParams] = finance_scope_clause($pdo, 'e', $userId, $orgId, 'created_by');
    $overviewStmt = $pdo->prepare(
        "SELECT COUNT(*) expense_count,
                COALESCE(SUM(COALESCE(e.total_amount,e.amount,0)),0) expense_total,
                COALESCE(SUM(CASE WHEN e.expense_date >= ? THEN COALESCE(e.total_amount,e.amount,0) ELSE 0 END),0) expense_ytd
         FROM expenses e WHERE {$overviewExpenseWhere} AND e.status != 'void'"
    );
    $overviewStmt->execute(array_merge([date('Y') . '-01-01'], $overviewExpenseParams));
    $expenseOverview = $overviewStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $overview['expense_count'] = (int)($expenseOverview['expense_count'] ?? 0);
    $overview['expense_total'] = (float)($expenseOverview['expense_total'] ?? 0);
    $overview['expense_ytd'] = (float)($expenseOverview['expense_ytd'] ?? 0);

    [$overviewRecurringWhere, $overviewRecurringParams] = finance_scope_clause($pdo, 're', $userId, $orgId, 'created_by');
    $overviewStmt = $pdo->prepare("SELECT * FROM recurring_expenses re WHERE {$overviewRecurringWhere} AND re.status='active'");
    $overviewStmt->execute($overviewRecurringParams);
    foreach ($overviewStmt->fetchAll(PDO::FETCH_ASSOC) as $overviewSchedule) {
        $overview['recurring_count']++;
        $overview['recurring_annual'] += recurring_expense_annualized_amount($overviewSchedule);
    }

    [$overviewReceiptWhere, $overviewReceiptParams] = finance_scope_clause($pdo, 'r', $userId, $orgId, 'uploaded_by');
    $overviewStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT r.id) receipt_count,
                COUNT(DISTINCT CASE WHEN e.id IS NULL THEN r.id END) receipt_unmatched
         FROM receipts r
         LEFT JOIN expenses e ON e.receipt_id=r.id AND e.status!='void'
         WHERE {$overviewReceiptWhere}"
    );
    $overviewStmt->execute($overviewReceiptParams);
    $receiptOverview = $overviewStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $overview['receipt_count'] = (int)($receiptOverview['receipt_count'] ?? 0);
    $overview['receipt_unmatched'] = (int)($receiptOverview['receipt_unmatched'] ?? 0);

    [$overviewMileageWhere, $overviewMileageParams] = finance_scope_clause($pdo, 'm', $userId, $orgId, 'user_id');
    $overviewStmt = $pdo->prepare(
        "SELECT COUNT(*) mileage_trips,
                COALESCE(SUM(m.miles * CASE WHEN m.round_trip=1 THEN 2 ELSE 1 END),0) mileage_miles,
                COALESCE(SUM(m.miles * CASE WHEN m.round_trip=1 THEN 2 ELSE 1 END * m.mileage_rate),0) mileage_amount,
                COALESCE(SUM(CASE WHEN m.is_billable=1 AND m.billed=0 THEN m.miles * CASE WHEN m.round_trip=1 AND m.bill_return_trip=1 THEN 2 ELSE 1 END ELSE 0 END),0) mileage_unbilled
         FROM mileage_logs m WHERE {$overviewMileageWhere}"
    );
    $overviewStmt->execute($overviewMileageParams);
    $mileageOverview = $overviewStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['mileage_trips' => 'mileage_trips', 'mileage_miles' => 'mileage_miles', 'mileage_amount' => 'mileage_amount', 'mileage_unbilled' => 'mileage_unbilled'] as $target => $source) {
        $overview[$target] = str_ends_with($target, 'trips') ? (int)($mileageOverview[$source] ?? 0) : (float)($mileageOverview[$source] ?? 0);
    }
} catch (Throwable $overviewError) {
    @error_log('[FinancialOverview] ' . $overviewError->getMessage());
}

$overviewMoney = static fn(float $value): string => '$' . number_format($value, 2);
?>

<section class="expense-ledger finance-overview">
  <div class="expense-ledger__head">
    <div>
      <h2>Overview</h2>
      <p class="muted">An all-time snapshot of owned assets, recorded expenses, supporting receipts and recurring schedules, and mileage.</p>
    </div>
    <div class="finance-actions">
      <a href="/?page=financial/asset-form" class="btn">Add Asset</a>
      <a href="/?page=financial/mileage-create" class="btn">Log Mileage</a>
      <a href="/?page=financial/receipt-upload" class="btn">Upload Receipt</a>
      <a href="/?page=financial/expense-create" class="btn btn-primary">Add Expense</a>
    </div>
  </div>

  <div class="finance-overview-grid">
    <article class="finance-overview-card">
      <div class="finance-overview-card__head"><div><span>Assets</span><strong><?php echo number_format($overview['asset_count']); ?></strong></div><a href="/?page=financial/expenses-list&amp;tab=assets">View assets</a></div>
      <dl>
        <div><dt>Purchase cost</dt><dd><?php echo $overviewMoney($overview['asset_purchase_cost']); ?></dd></div>
        <div><dt>Current book value</dt><dd><?php echo $overviewMoney($overview['asset_book_value']); ?></dd></div>
      </dl>
    </article>

    <article class="finance-overview-card">
      <div class="finance-overview-card__head"><div><span>Expenses</span><strong><?php echo $overviewMoney($overview['expense_total']); ?></strong></div><a href="/?page=financial/expenses-list&amp;tab=expenses">View expenses</a></div>
      <dl>
        <div><dt>Expense records</dt><dd><?php echo number_format($overview['expense_count']); ?></dd></div>
        <div><dt>Year to date</dt><dd><?php echo $overviewMoney($overview['expense_ytd']); ?></dd></div>
        <div><dt>Active recurring schedules</dt><dd><?php echo number_format($overview['recurring_count']); ?></dd></div>
        <div><dt>Receipts on file</dt><dd><?php echo number_format($overview['receipt_count']); ?></dd></div>
      </dl>
      <p class="finance-overview-card__note">Recurring schedules and receipts support the expense ledger; they are not added again to the expense total.</p>
    </article>

    <article class="finance-overview-card">
      <div class="finance-overview-card__head"><div><span>Mileage</span><strong><?php echo number_format($overview['mileage_miles'], 2); ?> mi</strong></div><a href="/?page=financial/expenses-list&amp;tab=mileage">View mileage</a></div>
      <dl>
        <div><dt>Trips logged</dt><dd><?php echo number_format($overview['mileage_trips']); ?></dd></div>
        <div><dt>Mileage amount</dt><dd><?php echo $overviewMoney($overview['mileage_amount']); ?></dd></div>
        <div><dt>Unbilled client miles</dt><dd><?php echo number_format($overview['mileage_unbilled'], 2); ?></dd></div>
      </dl>
    </article>
  </div>

  <div class="expense-summary">
    <a class="expense-stat finance-overview-link" href="/?page=financial/expenses-list&amp;tab=recurring"><span>Recurring expenses</span><strong><?php echo number_format($overview['recurring_count']); ?></strong><small><?php echo $overviewMoney($overview['recurring_annual']); ?> annual forecast</small></a>
    <a class="expense-stat finance-overview-link" href="/?page=financial/expenses-list&amp;tab=receipts"><span>Receipts</span><strong><?php echo number_format($overview['receipt_count']); ?></strong><small><?php echo number_format($overview['receipt_unmatched']); ?> not linked to an expense</small></a>
    <a class="expense-stat finance-overview-link" href="/?page=financial/expenses-list&amp;tab=vendors"><span>Vendors</span><strong><?php echo number_format($stats['vendors'] ?? 0); ?></strong><small>Expense suppliers</small></a>
    <a class="expense-stat finance-overview-link" href="/?page=financial/expenses-list&amp;tab=categories"><span>Categories</span><strong><?php echo number_format($stats['categories'] ?? 0); ?></strong><small>Expense and tax groupings</small></a>
  </div>
</section>
