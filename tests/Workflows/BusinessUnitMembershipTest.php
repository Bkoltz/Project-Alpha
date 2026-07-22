<?php

declare(strict_types=1);

namespace Tests\Workflows;

use PHPUnit\Framework\TestCase;

final class BusinessUnitMembershipTest extends TestCase
{
    public function testForwardMigrationPreservesHistoryAndAddsManagerModel(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string)file_get_contents($root . '/database/migrations/0055_business_unit_memberships_and_project_managers.sql');

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS business_unit_memberships', $migration);
        self::assertStringContainsString("membership_role ENUM('member','head')", $migration);
        self::assertStringContainsString('INDEX idx_business_unit_membership_pair', $migration);
        self::assertStringNotContainsString('UNIQUE KEY uq_business_unit_membership_pair', $migration);
        self::assertStringContainsString("CASE WHEN wbu.is_lead=1 THEN 'head' ELSE 'member' END", $migration);
        self::assertStringContainsString('ADD COLUMN manager_user_id', $migration);
        self::assertStringContainsString('fk_projects_manager', $migration);
        self::assertStringContainsString('HAVING COUNT(*)=1', $migration);
        self::assertStringContainsString('WHERE entitlement.application_key=@external_ops_app_key', $migration);
        self::assertStringContainsString('entitlement.manual_enabled=1', $migration);
    }

    public function testSettingsActionsAreProtectedTransactionalAndAudited(): void
    {
        $root = dirname(__DIR__, 2);
        $handler = (string)file_get_contents($root . '/src/controllers/settings/workforce_catalog_handler.php');
        $view = (string)file_get_contents($root . '/src/views/pages/settings/business-units-divisions.php');

        self::assertStringContainsString("'save-unit-membership','end-unit-membership'", $handler);
        self::assertStringContainsString('csrf_validate()', $handler);
        self::assertStringContainsString("\$action==='save-unit-membership'", $handler);
        self::assertStringContainsString("\$action==='end-unit-membership'", $handler);
        self::assertStringContainsString('WHERE id=? AND is_active=1 FOR UPDATE', $handler);
        self::assertStringContainsString('u.is_disabled=0', $view);
        self::assertStringContainsString('UPDATE business_unit_memberships SET is_primary=0 WHERE user_id=?', $handler);
        self::assertStringContainsString("business_unit.membership.saved", $handler);
        self::assertStringContainsString("business_unit.membership.ended", $handler);
        self::assertStringContainsString('name="membership_role"', $view);
        self::assertStringContainsString('name="is_primary"', $view);
        self::assertStringContainsString('Former', $view);
        self::assertStringContainsString('does not grant workforce permissions', $view);
    }
}
