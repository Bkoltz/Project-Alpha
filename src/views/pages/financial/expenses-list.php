<?php
// src/views/pages/financial/expenses-list.php
// Unified expenses hub: Expenses, Receipts, Mileage, Vendors, Categories, Audit

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';

$csrfToken = csrf_sf_token('expense');

function include_financial_tab(string $file): string {
    ob_start();
    include __DIR__ . '/' . $file;
    return ob_get_clean();
}

$tabs = [
    'expenses'   => ['label' => 'Expenses',   'file' => '_expenses_tab.php'],
    'receipts'   => ['label' => 'Receipts',   'file' => 'receipts-list.php'],
    'mileage'    => ['label' => 'Mileage',    'file' => 'mileage-list.php'],
    'vendors'    => ['label' => 'Vendors',    'file' => 'vendors-list.php'],
    'categories' => ['label' => 'Categories', 'file' => 'categories-list.php'],
    'audit'      => ['label' => 'Audit',      'file' => 'audit.php'],
];

$active = $_GET['tab'] ?? 'expenses';
if (!isset($tabs[$active])) $active = 'expenses';
?>

<style>
.expenses-hub { max-width:1600px; margin:0 auto; width:100%; box-sizing:border-box; }
.expenses-hub__head { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
.expenses-hub__head h2 { margin:0; font-size:24px; }
.expenses-hub__tabs { display:flex; gap:6px; flex-wrap:wrap; border-bottom:1px solid var(--border); margin-bottom:20px; padding-bottom:6px; }
.expenses-hub__tab { padding:10px 16px; border-radius:var(--radius-sm) var(--radius-sm) 0 0; font-weight:600; color:var(--muted); background:transparent; border:none; cursor:pointer; }
.expenses-hub__tab:hover { color:var(--text); background:var(--surface-2); }
.expenses-hub__tab.active { color:var(--nav-accent); background:var(--surface); box-shadow:inset 0 -2px 0 var(--nav-accent); }
.expenses-hub__panel { display:none; max-width:100%; }
.expenses-hub__panel.active { display:block; }
.expenses-hub__panel > div > .page-head:first-child { display:none; }
.expenses-hub__panel .grid, .expenses-hub__panel form.grid, .expenses-hub__panel form.filter-form { grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); }
@media (max-width:900px) {
  .expenses-hub__tabs { flex-direction:column; }
  .expenses-hub__panel .grid, .expenses-hub__panel form.grid, .expenses-hub__panel form.filter-form { grid-template-columns:1fr; }
}
</style>

<div class="expenses-hub" style="padding:24px;max-width:100%">
  <div class="expenses-hub__head">
    <h2>Expenses Hub</h2>
    <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="/?page=financial/expense-report" class="btn btn-sm">Reports</a>
      <a href="/?page=financial/csv-import" class="btn btn-sm">Import CSV</a>
      <a href="/?page=financial/expense-create" class="btn btn-primary">Add Expense</a>
    </div>
  </div>

  <div class="expenses-hub__tabs" role="tablist" aria-label="Expenses sections">
    <?php foreach ($tabs as $id => $t): ?>
      <a href="/?page=financial/expenses-list&tab=<?php echo htmlspecialchars($id); ?>"
         class="expenses-hub__tab <?php echo $active === $id ? 'active' : ''; ?>"
         data-tab="<?php echo htmlspecialchars($id); ?>"
         role="tab"
         aria-selected="<?php echo $active === $id ? 'true' : 'false'; ?>"
      ><?php echo htmlspecialchars($t['label']); ?></a>
    <?php endforeach; ?>
  </div>

  <?php foreach ($tabs as $id => $t): ?>
    <div class="expenses-hub__panel <?php echo $active === $id ? 'active' : ''; ?>" id="tab-<?php echo htmlspecialchars($id); ?>" role="tabpanel">
      <?php ob_start(); include __DIR__ . '/' . $t['file']; echo ob_get_clean(); ?>
    </div>
  <?php endforeach; ?>
</div>

<script src="/assets/js/expenses-hub.js" defer></script>
