<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/views/pages/settings/registry.php';

use PHPUnit\Framework\TestCase;

final class SettingsRedesignTest extends TestCase
{
    public function testEveryLegacySettingsTabAppearsExactlyOnce(): void
    {
        $tabs = [];
        foreach (pa_settings_registry() as $category) {
            foreach ($category['items'] as $item) {
                if (isset($item['tab'])) {
                    $tabs[] = $item['tab'];
                }
            }
        }

        $expected = [
            'backup', 'billing', 'documents', 'item-library', 'links', 'logs',
            'notifications', 'permissions', 'system', 'taxes', 'terms', 'workflow',
        ];
        sort($tabs);
        sort($expected);

        foreach ($expected as $legacyTab) {
            self::assertSame(1, count(array_keys($tabs, $legacyTab, true)), $legacyTab . ' must appear exactly once.');
        }
        self::assertSame($tabs, array_values(array_unique($tabs)));
    }

    public function testAccountSecurityRemainsVisibleWithoutInstallationManagement(): void
    {
        $registry = pa_settings_visible_registry(
            pa_settings_registry(),
            static fn (string $permission): bool => false,
            'employee'
        );

        self::assertSame(['account'], array_keys($registry));
        self::assertArrayHasKey('profile', $registry['account']['items']);
        self::assertArrayHasKey('security', $registry['account']['items']);
    }

    public function testInstallationCategoriesArePermissionAndRoleAware(): void
    {
        $canManage = static fn (string $permission): bool => $permission === 'settings.manage';
        $staffRegistry = pa_settings_visible_registry(pa_settings_registry(), $canManage, 'staff');
        $ownerRegistry = pa_settings_visible_registry(pa_settings_registry(), $canManage, 'owner');

        self::assertArrayNotHasKey('business', $staffRegistry);
        self::assertArrayHasKey('business', $ownerRegistry);
        self::assertArrayHasKey('workflow', $staffRegistry['work']['items']);
        self::assertArrayNotHasKey('accounts', $staffRegistry['people']['items']);
        self::assertArrayNotHasKey('api-keys', $staffRegistry['data']['items']);
    }

    public function testSettingsShellUsesSharedNavigationAndExplicitSaveControls(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string)file_get_contents($root . '/src/views/pages/settings.php');
        $script = (string)file_get_contents($root . '/public/assets/js/settings-page.js');
        $styles = (string)file_get_contents($root . '/public/assets/settings.css');

        self::assertStringContainsString("settings/registry.php", $view);
        self::assertStringContainsString("settings/dashboard.php", $view);
        self::assertStringContainsString("settings/sidebar.php", $view);
        self::assertStringContainsString('data-settings-primary-form', $view);
        self::assertStringContainsString('data-settings-cancel', $view);
        self::assertStringContainsString('Save changes', $view);
        self::assertStringContainsString("registerPage('settings'", $script);
        self::assertStringContainsString("beforeunload", $script);
        self::assertStringContainsString('You have unsaved changes.', $script);
        self::assertStringContainsString('position: sticky', $styles);
        self::assertStringContainsString('.settings-category-grid', $styles);
    }

    public function testSharedSettingsStylesKeepFormsReadableAndActionsInline(): void
    {
        $styles = (string)file_get_contents(dirname(__DIR__, 2) . '/public/assets/settings.css');

        self::assertStringContainsString('--settings-muted: #475467', $styles);
        self::assertStringContainsString('--settings-control-border: #8491a5', $styles);
        self::assertStringContainsString('.settings-managed-section .settings-card', $styles);
        self::assertStringContainsString('.settings-form-grid', $styles);
        self::assertStringContainsString('.settings-content .check-row', $styles);
        self::assertStringContainsString('.settings-managed-section .inline-form', $styles);
        self::assertStringContainsString('.settings-action-row', $styles);
        self::assertStringContainsString('display: inline-flex', $styles);
        self::assertStringContainsString('.settings-form-grid { grid-template-columns: 1fr; }', $styles);
    }

    public function testLegacyAccountAndCustomizationAliasesRemainSupported(): void
    {
        $view = (string)file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/settings.php');

        self::assertStringContainsString("\$requestedTab === 'account'", $view);
        self::assertStringContainsString("\$requestedTab === 'customization'", $view);
        self::assertStringContainsString("\$_GET['doc_tab'] = 'customization'", $view);
    }
}
