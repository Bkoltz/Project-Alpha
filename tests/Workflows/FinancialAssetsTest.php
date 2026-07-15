<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FinancialAssetsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testAssetSchemaIsInBaselineAndForwardMigration(): void
    {
        $baseline = $this->read('database/baseline.sql');
        $migration = $this->read('database/migrations/0009_financial_assets.sql');

        foreach ([$baseline, $migration] as $sql) {
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS financial_assets', $sql);
            self::assertStringContainsString('asset_tag VARCHAR(80)', $sql);
            self::assertStringContainsString("depreciation_method ENUM('none','straight_line')", $sql);
            self::assertStringContainsString('useful_life_months INT', $sql);
            self::assertStringContainsString('CONSTRAINT fk_fin_asset_org', $sql);
        }
    }

    public function testAssetRoutesAndPermissionsAreRegistered(): void
    {
        $front = $this->read('public/index.php');
        self::assertStringContainsString("'financial/asset-handler'", $front);
        self::assertStringContainsString('src/controllers/financial/asset_handler.php', $front);

        $acl = $this->read('src/utils/acl_middleware.php');
        self::assertStringContainsString("'financial/asset-detail'           => 'financial.view'", $acl);
        self::assertStringContainsString("'financial/asset-form'             => 'financial.manage'", $acl);
        self::assertStringContainsString("'financial/asset-handler'          => 'financial.manage'", $acl);
    }

    public function testExpenseRoutesPermissionsAndFormSubmitAreRegistered(): void
    {
        $front = $this->read('public/index.php');
        self::assertStringContainsString("'financial/expense-handler'", $front);
        self::assertStringContainsString("'financial/expense_handler'", $front);
        self::assertStringContainsString('src/controllers/financial/expense_handler.php', $front);

        $acl = $this->read('src/utils/acl_middleware.php');
        self::assertStringContainsString("'financial/expense-create'         => 'financial.manage'", $acl);
        self::assertStringContainsString("'financial/expense-detail'         => 'financial.view'", $acl);
        self::assertStringContainsString("'financial/expense-handler'        => 'financial.manage'", $acl);
        self::assertStringContainsString("'financial/expense_handler'        => 'financial.manage'", $acl);

        $form = $this->read('src/views/pages/financial/expense-create.php');
        self::assertStringContainsString('action="/?page=financial/expense-handler"', $form);
        self::assertStringContainsString('(function () {', $form);
        self::assertStringNotContainsString('fetch(form.action', $form);
        self::assertStringContainsString('$_GET[\'error\']', $form);
        self::assertStringContainsString('/?page=financial/expenses-list&tab=expenses', $form);
        self::assertStringContainsString('id="newCategoryForm"', $form);
        self::assertStringContainsString('id="expenseCategory"', $form);
        self::assertStringContainsString('New Category', $form);
        self::assertStringContainsString('payload.id', $form);

        $handler = $this->read('src/controllers/financial/expense_handler.php');
        self::assertStringContainsString('function expense_handler_is_ajax()', $handler);
        self::assertStringContainsString("'status_param' => 'created'", $handler);
        self::assertStringContainsString("'status_param' => 'updated'", $handler);
        self::assertStringContainsString('/?page=financial/expenses-list&tab=expenses', $handler);

        $categoryHandler = $this->read('src/controllers/financial/category_handler.php');
        self::assertStringContainsString('function category_handler_safe_return_url', $categoryHandler);
        self::assertStringContainsString('category_id', $categoryHandler);
        self::assertStringContainsString("'name' => \$name", $categoryHandler);

        $categories = $this->read('src/views/pages/financial/categories-list.php');
        self::assertStringContainsString('onclick="createCategory()"', $categories);
        self::assertStringContainsString('id="categoryAction"', $categories);
        self::assertStringContainsString('window.createCategory', $categories);
        self::assertStringNotContainsString('/?page=financial/vendor-form" class="btn btn-primary">+ Add Category', $categories);
    }

    public function testAssetsAreExposedInFinancialHubUi(): void
    {
        $hub = $this->read('src/views/pages/financial/expenses-list.php');
        $script = $this->read('public/assets/js/expenses-hub.js');
        $dashboard = $this->read('src/views/pages/financial/financial-dashboard.php');
        $nav = $this->read('src/views/partials/header.php');
        $overview = $this->read('src/views/pages/financial/_overview_tab.php');

        self::assertStringContainsString("'overview'   => ['label' => 'Overview'", $hub);
        self::assertStringContainsString("'assets'     => ['label' => 'Assets'", $hub);
        self::assertStringContainsString('Assets &amp; Expenses', $hub);
        self::assertStringContainsString('financial_assets', $hub);
        self::assertStringContainsString("\$active = \$_GET['tab'] ?? 'overview';", $hub);
        self::assertStringContainsString("params.get('tab') || 'overview'", $script);
        self::assertStringContainsString('href="/?page=financial/expenses-list" class="btn btn-primary">Assets &amp; Expenses', $dashboard);
        self::assertStringContainsString('href="/?page=financial/expenses-list" data-page="financial/expenses-list">Assets &amp; Expenses', $nav);
        self::assertStringContainsString('asset_purchase_cost', $overview);
        self::assertStringContainsString('expense_total', $overview);
        self::assertStringContainsString('mileage_miles', $overview);
        self::assertStringContainsString('Recurring schedules and receipts support the expense ledger', $overview);

        $tab = $this->read('src/views/pages/financial/_assets_tab.php');
        self::assertStringContainsString('financial_asset_depreciation', $tab);
        self::assertStringContainsString('Monthly Depreciation', $tab);
        self::assertStringContainsString('Book Value', $tab);

        self::assertStringContainsString('Assets &amp; Expenses', $nav);
    }

    public function testAssetCreateAndDetailPagesIncludeLifecycleAndDepreciationControls(): void
    {
        $form = $this->read('src/views/pages/financial/asset-form.php');
        self::assertStringContainsString("csrf_sf_token('asset')", $form);
        self::assertStringContainsString('action="/?page=financial/asset-handler"', $form);
        self::assertStringNotContainsString("fetch('/?page=financial/asset-handler'", $form);
        self::assertStringContainsString('Useful Life (months)', $form);
        self::assertStringContainsString('Estimated Monthly', $form);
        self::assertStringContainsString('Linked Expense', $form);
        self::assertStringContainsString('Make new expense from this asset', $form);
        self::assertStringContainsString('data-vendor-id', $form);
        self::assertStringContainsString('applyLinkedExpense', $form);
        self::assertStringContainsString('Warranty Expires', $form);

        $detail = $this->read('src/views/pages/financial/asset-detail.php');
        self::assertStringContainsString('Accumulated Depreciation', $detail);
        self::assertStringContainsString('Linked Expense', $detail);
        self::assertStringContainsString('Mark Disposed', $detail);
        self::assertStringNotContainsString("fetch('/?page=financial/asset-handler'", $detail);

        $handler = $this->read('src/controllers/financial/asset_handler.php');
        self::assertStringContainsString('function asset_handler_finish', $handler);
        self::assertStringContainsString('function asset_fetch_expense', $handler);
        self::assertStringContainsString('$createExpenseFromAsset', $handler);
        self::assertStringContainsString('Asset purchase: ', $handler);
        self::assertStringContainsString('UPDATE expenses SET vendor_id = ?', $handler);
        self::assertStringContainsString('request_client_org_id()', $handler);
        self::assertStringContainsString('finance_scope_clause($pdo, \'a\'', $handler);
    }

    public function testFinancialDashboardsUseExpenseAmountFallbacks(): void
    {
        $home = $this->read('src/views/pages/home.php');
        $dashboard = $this->read('src/views/pages/financial/financial-dashboard.php');
        $expensesTab = $this->read('src/views/pages/financial/_expenses_tab.php');

        self::assertStringContainsString('COALESCE(e.total_amount, e.amount, 0)', $home);
        self::assertStringContainsString('COALESCE(e.total_amount, e.amount, 0)', $dashboard);
        self::assertStringContainsString('display_total', $dashboard);
        self::assertStringContainsString('COALESCE(e.total_amount, e.amount, 0)', $expensesTab);
        self::assertStringContainsString("\$dashboard_finance_period_start = date('Y') . '-01-01'", $home);
        self::assertStringContainsString('dashboard_finance_subtitle', $home);
        self::assertStringContainsString('dashboard_actionable_invoice_where', $home);
        self::assertStringContainsString("invoice_type != 'long_term'", $home);
        self::assertStringContainsString('actionable_unpaid', $home);
    }

    public function testDashboardExpenseApisUseScopedActiveTotals(): void
    {
        $financialApi = $this->read('src/controllers/api/financial_summary.php');
        $dashboardApi = $this->read('src/controllers/api/dashboard_summary.php');

        foreach ([$financialApi, $dashboardApi] as $source) {
            self::assertStringContainsString('finance_scope_clause', $source);
            self::assertStringContainsString("e.status != 'void'", $source);
            self::assertStringContainsString('COALESCE(e.total_amount, e.amount, 0)', $source);
        }

        self::assertStringContainsString("'expense_total'", $dashboardApi);
    }

    public function testFinancialHubUsesConsistentFinanceScope(): void
    {
        $acl = $this->read('src/utils/acl.php');
        self::assertStringContainsString('function finance_scope_clause', $acl);

        foreach ([
            'src/views/pages/financial/expenses-list.php',
            'src/views/pages/financial/_expenses_tab.php',
            'src/views/pages/financial/_assets_tab.php',
            'src/views/pages/financial/_overview_tab.php',
            'src/views/pages/financial/financial-dashboard.php',
            'src/views/pages/financial/expense-report.php',
            'src/controllers/financial/expense_export.php',
        ] as $path) {
            self::assertStringContainsString('finance_scope_clause', $this->read($path), $path);
        }
    }

    public function testFinancialHubUsesOneConsistentActionHeaderPerTab(): void
    {
        $hub = $this->read('src/views/pages/financial/expenses-list.php');
        self::assertStringNotContainsString('<div class="finance-actions">', $hub);

        foreach ([
            '_overview_tab.php', '_assets_tab.php', '_expenses_tab.php', '_recurring_expenses_tab.php',
            'receipts-list.php', 'mileage-list.php', 'vendors-list.php', 'categories-list.php', 'audit.php',
        ] as $file) {
            self::assertStringContainsString('expense-ledger__head', $this->read('src/views/pages/financial/' . $file), $file);
        }
        self::assertSame(1, substr_count($this->read('src/views/pages/financial/_assets_tab.php'), '>Add Asset</a>'));
        self::assertSame(1, substr_count($this->read('src/views/pages/financial/_expenses_tab.php'), '>Add Expense</a>'));
        self::assertSame(1, substr_count($this->read('src/views/pages/financial/_recurring_expenses_tab.php'), '>Add Recurring Expense</a>'));
        self::assertSame(1, substr_count($this->read('src/views/pages/financial/receipts-list.php'), '>Upload Receipt</a>'));
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->root . '/' . $relativePath);
        self::assertIsString($contents, $relativePath);
        return $contents;
    }
}
