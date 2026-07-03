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

        $handler = $this->read('src/controllers/financial/expense_handler.php');
        self::assertStringContainsString('function expense_handler_is_ajax()', $handler);
        self::assertStringContainsString("'status_param' => 'created'", $handler);
        self::assertStringContainsString("'status_param' => 'updated'", $handler);
        self::assertStringContainsString('/?page=financial/expenses-list&tab=expenses', $handler);
    }

    public function testAssetsAreExposedInFinancialHubUi(): void
    {
        $hub = $this->read('src/views/pages/financial/expenses-list.php');
        $script = $this->read('public/assets/js/expenses-hub.js');
        $dashboard = $this->read('src/views/pages/financial/financial-dashboard.php');
        $nav = $this->read('src/views/partials/header.php');

        self::assertStringContainsString("'assets'     => ['label' => 'Assets'", $hub);
        self::assertStringContainsString('Assets &amp; Expenses', $hub);
        self::assertStringContainsString('financial_assets', $hub);
        self::assertStringContainsString("\$active = \$_GET['tab'] ?? 'expenses';", $hub);
        self::assertStringContainsString("params.get('tab') || 'expenses'", $script);
        self::assertStringContainsString('/?page=financial/expenses-list&tab=expenses', $dashboard);
        self::assertStringContainsString('/?page=financial/expenses-list&tab=expenses', $nav);

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
        self::assertStringContainsString('Warranty Expires', $form);

        $detail = $this->read('src/views/pages/financial/asset-detail.php');
        self::assertStringContainsString('Accumulated Depreciation', $detail);
        self::assertStringContainsString('Linked Expense', $detail);
        self::assertStringContainsString('Mark Disposed', $detail);
        self::assertStringNotContainsString("fetch('/?page=financial/asset-handler'", $detail);

        $handler = $this->read('src/controllers/financial/asset_handler.php');
        self::assertStringContainsString('function asset_handler_finish', $handler);
        self::assertStringContainsString('active_or_default_org_id($pdo)', $handler);
        self::assertStringContainsString('finance_scope_clause($pdo, \'a\'', $handler);
    }

    public function testFinancialHubUsesConsistentFinanceScope(): void
    {
        $acl = $this->read('src/utils/acl.php');
        self::assertStringContainsString('function finance_scope_clause', $acl);

        foreach ([
            'src/views/pages/financial/expenses-list.php',
            'src/views/pages/financial/_expenses_tab.php',
            'src/views/pages/financial/_assets_tab.php',
            'src/views/pages/financial/financial-dashboard.php',
            'src/views/pages/financial/expense-report.php',
            'src/controllers/financial/expense_export.php',
        ] as $path) {
            self::assertStringContainsString('finance_scope_clause', $this->read($path), $path);
        }
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->root . '/' . $relativePath);
        self::assertIsString($contents, $relativePath);
        return $contents;
    }
}
