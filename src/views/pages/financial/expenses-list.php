<?php
// src/views/pages/financial/expenses-list.php
// Unified assets and expenses hub: Assets, Expenses, Receipts, Mileage, Vendors, Categories, Audit

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/acl.php';

$orgId = get_active_org_id();
$csrfToken = csrf_sf_token('expense');

$tabs = [
    'assets'     => ['label' => 'Assets',     'file' => '_assets_tab.php',     'hint' => 'Equipment and depreciation'],
    'expenses'   => ['label' => 'Expenses',   'file' => '_expenses_tab.php',   'hint' => 'Spending ledger'],
    'receipts'   => ['label' => 'Receipts',   'file' => 'receipts-list.php',   'hint' => 'Uploads and matches'],
    'mileage'    => ['label' => 'Mileage',    'file' => 'mileage-list.php',    'hint' => 'Business trips'],
    'vendors'    => ['label' => 'Vendors',    'file' => 'vendors-list.php',    'hint' => 'Suppliers'],
    'categories' => ['label' => 'Categories', 'file' => 'categories-list.php', 'hint' => 'Tax buckets'],
    'audit'      => ['label' => 'Audit',      'file' => 'audit.php',           'hint' => 'Export reviews'],
];

$active = $_GET['tab'] ?? 'expenses';
if (!isset($tabs[$active])) $active = 'expenses';

$stats = [
    'assets' => 0,
    'expenses' => 0,
    'receipts' => 0,
    'mileage' => 0,
    'vendors' => 0,
    'categories' => 0,
    'audit' => 0,
];

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM financial_assets WHERE organization_id=? AND status NOT IN ('disposed','lost')");
    $countStmt->execute([$orgId]);
    $stats['assets'] = (int)$countStmt->fetchColumn();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE organization_id=? AND status != 'void'");
    $countStmt->execute([$orgId]);
    $stats['expenses'] = (int)$countStmt->fetchColumn();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM receipts WHERE organization_id=?");
    $countStmt->execute([$orgId]);
    $stats['receipts'] = (int)$countStmt->fetchColumn();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM mileage_logs WHERE organization_id=?");
    $countStmt->execute([$orgId]);
    $stats['mileage'] = (int)$countStmt->fetchColumn();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM vendors WHERE organization_id=? AND is_active=1");
    $countStmt->execute([$orgId]);
    $stats['vendors'] = (int)$countStmt->fetchColumn();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM expense_categories WHERE organization_id=?");
    $countStmt->execute([$orgId]);
    $stats['categories'] = (int)$countStmt->fetchColumn();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_schedules WHERE organization_id=?");
    $countStmt->execute([$orgId]);
    $stats['audit'] = (int)$countStmt->fetchColumn();
} catch (Throwable $ignored) {
    // Some dev databases may be mid-migration; the tab content handles its own errors.
}
?>

<div class="expenses-hub">
  <div class="finance-page-head expenses-hub__head">
    <div>
      <p class="finance-eyebrow">Financial workspace</p>
      <h2>Assets &amp; Expenses</h2>
      <p class="finance-subtitle">Track owned assets, depreciation, spending, receipts, mileage, vendors, categories, and audit exports from one place.</p>
    </div>
    <div class="finance-actions">
      <a href="/?page=financial/asset-form" class="btn">Add Asset</a>
      <a href="/?page=financial/expense-report" class="btn">Reports</a>
      <a href="/?page=financial/csv-import" class="btn">Import CSV</a>
      <a href="/?page=financial/expense-create" class="btn btn-primary">Add Expense</a>
    </div>
  </div>

  <div class="expenses-hub__tabs" role="tablist" aria-label="Expenses sections">
    <?php foreach ($tabs as $id => $t): ?>
      <a href="/?page=financial/expenses-list&tab=<?php echo htmlspecialchars($id); ?>"
         class="expenses-hub__tab <?php echo $active === $id ? 'active' : ''; ?>"
         data-tab="<?php echo htmlspecialchars($id); ?>"
         role="tab"
         aria-selected="<?php echo $active === $id ? 'true' : 'false'; ?>">
        <span><?php echo htmlspecialchars($t['label']); ?></span>
        <small><?php echo htmlspecialchars($t['hint']); ?></small>
        <b><?php echo number_format($stats[$id] ?? 0); ?></b>
      </a>
    <?php endforeach; ?>
  </div>

  <?php foreach ($tabs as $id => $t): ?>
    <div class="expenses-hub__panel <?php echo $active === $id ? 'active' : ''; ?>" id="tab-<?php echo htmlspecialchars($id); ?>" role="tabpanel">
      <?php ob_start(); include __DIR__ . '/' . $t['file']; echo ob_get_clean(); ?>
    </div>
  <?php endforeach; ?>
</div>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/expenses-hub.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
