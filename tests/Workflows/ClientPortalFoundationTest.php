<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ClientPortalFoundationTest extends TestCase
{
    private string $root;
    private string $migration;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->migration = (string) file_get_contents(
            $this->root . '/database/migrations/0062_client_portal_foundation.sql'
        );
    }

    public function testFoundationIsDefaultOffAndDoesNotInstallAPublicRoute(): void
    {
        self::assertStringContainsString("VALUES (0, 'client_portal_enabled', '0')", $this->migration);
        self::assertStringContainsString('INSERT IGNORE INTO app_config', $this->migration);
        self::assertStringNotContainsString("ON DUPLICATE KEY UPDATE config_value = '0'", $this->migration);

        $frontController = (string) file_get_contents($this->root . '/public/index.php');
        self::assertStringNotContainsString('portal_principals', $frontController);
        self::assertStringNotContainsString('client_portal_enabled', $frontController);
    }

    public function testAllPortalIdentityAndEntitlementRecordsStartDisabled(): void
    {
        self::assertMatchesRegularExpression(
            '/CREATE TABLE IF NOT EXISTS portal_principals .*?enabled TINYINT\(1\) NOT NULL DEFAULT 0/s',
            $this->migration
        );
        self::assertMatchesRegularExpression(
            '/CREATE TABLE IF NOT EXISTS portal_identity_bindings .*?enabled TINYINT\(1\) NOT NULL DEFAULT 0/s',
            $this->migration
        );
        self::assertMatchesRegularExpression(
            '/CREATE TABLE IF NOT EXISTS portal_organization_entitlements .*?enabled TINYINT\(1\) NOT NULL DEFAULT 0/s',
            $this->migration
        );
        self::assertMatchesRegularExpression(
            '/CREATE TABLE IF NOT EXISTS portal_project_entitlements .*?enabled TINYINT\(1\) NOT NULL DEFAULT 0/s',
            $this->migration
        );
    }

    public function testPublicIdentifiersAreOpaqueUniqueAndSeparateFromLegacyProjectLinks(): void
    {
        foreach (['organizations', 'clients', 'projects'] as $table) {
            self::assertStringContainsString("UPDATE {$table} SET public_id = LOWER(HEX(RANDOM_BYTES(16)))", $this->migration);
            self::assertStringContainsString("uq_{$table}_public_id", $this->migration);
        }

        self::assertStringNotContainsString('public_project_token', $this->migration);
    }

    public function testMigrationIsRetrySafeAfterPartialDdl(): void
    {
        self::assertSame(5, substr_count($this->migration, 'CREATE TABLE IF NOT EXISTS portal_'));
        self::assertStringContainsString('information_schema.columns', $this->migration);
        self::assertStringContainsString('information_schema.statistics', $this->migration);
    }
}
