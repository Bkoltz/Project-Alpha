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

    public function testAssetsAreExposedInFinancialHubUi(): void
    {
        $hub = $this->read('src/views/pages/financial/expenses-list.php');
        self::assertStringContainsString("'assets'     => ['label' => 'Assets'", $hub);
        self::assertStringContainsString('Assets &amp; Expenses', $hub);
        self::assertStringContainsString('financial_assets', $hub);

        $tab = $this->read('src/views/pages/financial/_assets_tab.php');
        self::assertStringContainsString('financial_asset_depreciation', $tab);
        self::assertStringContainsString('Monthly Depreciation', $tab);
        self::assertStringContainsString('Book Value', $tab);

        $nav = $this->read('src/views/partials/header.php');
        self::assertStringContainsString('Assets &amp; Expenses', $nav);
    }

    public function testAssetCreateAndDetailPagesIncludeLifecycleAndDepreciationControls(): void
    {
        $form = $this->read('src/views/pages/financial/asset-form.php');
        self::assertStringContainsString("csrf_sf_token('asset')", $form);
        self::assertStringContainsString('Useful Life (months)', $form);
        self::assertStringContainsString('Estimated Monthly', $form);
        self::assertStringContainsString('Linked Expense', $form);
        self::assertStringContainsString('Warranty Expires', $form);

        $detail = $this->read('src/views/pages/financial/asset-detail.php');
        self::assertStringContainsString('Accumulated Depreciation', $detail);
        self::assertStringContainsString('Linked Expense', $detail);
        self::assertStringContainsString('Mark Disposed', $detail);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->root . '/' . $relativePath);
        self::assertIsString($contents, $relativePath);
        return $contents;
    }
}
