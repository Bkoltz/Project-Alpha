<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/resolver_link_policy.php';
require_once dirname(__DIR__, 2) . '/src/utils/invoice_links.php';
require_once dirname(__DIR__, 2) . '/src/utils/link_provider_config.php';
require_once dirname(__DIR__, 2) . '/src/link_resolvers/link_manager.php';

final class ResolverLinkPolicyTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-08-26 12:00:00');
        $this->pdo->exec('CREATE TABLE app_config (organization_id INTEGER, config_key TEXT, config_value TEXT)');
        $this->pdo->exec('CREATE TABLE link_resolver_config (id INTEGER PRIMARY KEY, provider TEXT, is_enabled INTEGER, credentials TEXT, default_expiration_days INTEGER, updated_at TEXT)');
        $this->pdo->exec('CREATE TABLE entity_links (id INTEGER PRIMARY KEY, entity_type TEXT, entity_id INTEGER, title TEXT, url TEXT, link_source TEXT, link_type TEXT, include_on_invoices INTEGER, is_expired INTEGER, expiration_date TEXT, visibility_scope TEXT)');
        $this->pdo->exec("INSERT INTO app_config VALUES (0,'link_resolver_enabled','1')");
        $insert = $this->pdo->prepare('INSERT INTO link_resolver_config VALUES (?, ?, 1, ?, 365, NULL)');
        $insert->execute([1, 'dropbox', '{"access_token":"synthetic-dropbox-token"}']);
        $insert->execute([2, 'gdrive', '{"service_account":"synthetic-google-credentials"}']);
        $links = $this->pdo->prepare('INSERT INTO entity_links VALUES (?, ?, 101, ?, ?, ?, ?, 1, 0, NULL, "entity_only")');
        foreach ([
            [1, 'organization', 'Dropbox', 'https://example.test/dropbox', 'resolver', 'auto_dropbox'],
            [2, 'organization', 'Google', 'https://example.test/google', 'resolver', 'auto_gdrive'],
            [3, 'organization', 'Manual Dropbox URL', 'https://example.test/manual-dropbox', 'manual', 'manual'],
            [4, 'organization', 'Resolver disabled', '#', 'resolver', 'resolver_blacklist'],
            [5, 'client', 'Dropbox client', 'https://example.test/client', 'resolver', 'auto_dropbox'],
            [6, 'department', 'Dropbox department', 'https://example.test/department', 'resolver', 'auto_dropbox'],
            [7, 'project', 'Dropbox project', 'https://example.test/project', 'resolver', 'auto_dropbox'],
        ] as $row) {
            $links->execute($row);
        }
        $this->pdo->exec('UPDATE entity_links SET include_on_invoices=0 WHERE id=4');
        $this->pdo->exec('ALTER TABLE entity_links ADD COLUMN ignore_auto_generation INTEGER DEFAULT 0');
    }

    public function testGlobalDisableHidesCachedLinksFromInvoiceReadersImmediately(): void
    {
        $this->pdo->exec("UPDATE app_config SET config_value='0' WHERE config_key='link_resolver_enabled'");
        self::assertSame([3], $this->invoiceLinkIds());
        $links = (new LinkResolverManager($this->pdo))->getAllLinksForClient(101);
        self::assertSame([], $links);
        self::assertSame(7, (int)$this->pdo->query('SELECT COUNT(*) FROM entity_links')->fetchColumn(), 'Reading does not delete records.');
    }

    public function testInternalBlacklistMarkersAreNeverVisible(): void
    {
        $visible = $this->pdo->query('SELECT id FROM entity_links WHERE ' . pa_resolver_link_visibility_sql() . ' ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([1, 2, 3, 5, 6, 7], array_map('intval', $visible));
    }

    public function testProviderDisableHidesOnlyThatProvider(): void
    {
        $this->pdo->exec("UPDATE link_resolver_config SET is_enabled=0 WHERE provider='dropbox'");
        self::assertSame([2, 3], $this->invoiceLinkIds());
        self::assertSame(4, pa_remove_disabled_resolver_links($this->pdo, 'dropbox'));
        self::assertSame([2, 3, 4], $this->storedLinkIds());
    }

    public function testGlobalCleanupPreservesCredentialsManualLinksAndBlacklist(): void
    {
        $credentials = $this->pdo->query('SELECT provider,credentials FROM link_resolver_config ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->pdo->exec("UPDATE app_config SET config_value='0'");
        self::assertSame(5, pa_remove_disabled_resolver_links($this->pdo));
        self::assertSame([3, 4], $this->storedLinkIds());
        self::assertSame($credentials, $this->pdo->query('SELECT provider,credentials FROM link_resolver_config ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR));
        self::assertSame(0, pa_remove_disabled_resolver_links($this->pdo), 'Cleanup is idempotent.');
        $this->pdo->exec("UPDATE app_config SET config_value='1'");
        self::assertSame([3], $this->invoiceLinkIds(), 'Re-enabling must not resurrect stale URLs.');
    }

    public function testMissingProviderConfigurationNeverExposesItsCachedUrls(): void
    {
        $this->pdo->exec("DELETE FROM link_resolver_config WHERE provider='dropbox'");
        self::assertSame([2, 3], $this->invoiceLinkIds());
    }

    public function testCleanupRetainsManualOnlyPreferenceStoredOnGeneratedLink(): void
    {
        $this->pdo->exec('UPDATE entity_links SET ignore_auto_generation=1 WHERE id=5');
        $this->pdo->exec("UPDATE app_config SET config_value='0'");
        self::assertSame(5, pa_remove_disabled_resolver_links($this->pdo));
        $marker = $this->pdo->query('SELECT link_type,url,ignore_auto_generation,include_on_invoices FROM entity_links WHERE id=5')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['link_type' => 'resolver_blacklist', 'url' => '#', 'ignore_auto_generation' => 1, 'include_on_invoices' => 0], $marker);
        self::assertSame([], (new LinkResolverManager($this->pdo))->getAllLinksForClient(101));
    }

    public function testProviderSaveDisablesLegacyDuplicatesWithoutLosingCanonicalCredentials(): void
    {
        $this->pdo->exec("INSERT INTO link_resolver_config VALUES (3,'dropbox',1,'{}',365,NULL)");
        $row = pa_link_provider_best_row($this->pdo, 'dropbox');
        $credentials = pa_link_provider_credentials_from_row($row);
        pa_link_provider_save($this->pdo, 'dropbox', 0, $credentials, 365);
        self::assertSame(0, (int)$this->pdo->query("SELECT SUM(is_enabled) FROM link_resolver_config WHERE provider='dropbox'")->fetchColumn());
        self::assertSame($credentials, pa_link_provider_credentials_from_row(pa_link_provider_best_row($this->pdo, 'dropbox')));
        self::assertSame([2, 3], $this->invoiceLinkIds());
    }

    public function testCleanupCanRollBackWithSettingsFailure(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->exec("UPDATE app_config SET config_value='0'");
        pa_remove_disabled_resolver_links($this->pdo);
        $this->pdo->rollBack();
        self::assertCount(7, $this->storedLinkIds());
        self::assertSame([1, 2, 3], $this->invoiceLinkIds());
    }

    public function testSaveDisconnectAndDisplayPathsUseTheSharedPolicy(): void
    {
        $root = dirname(__DIR__, 2);
        $handler = file_get_contents($root . '/src/controllers/settings/links_handler.php');
        self::assertSame(2, substr_count($handler, 'pa_remove_disabled_resolver_links($pdo)'));
        self::assertStringContainsString('$credentials = $existingCredentials;', $handler);
        self::assertStringContainsString('rollBack()', $handler);
        self::assertStringContainsString("pa_remove_disabled_resolver_links(\$pdo, 'dropbox')", file_get_contents($root . '/src/controllers/settings/dropbox_oauth.php'));
        foreach (['src/views/components/links_section.php', 'src/views/pages/organization/organization-view.php', 'src/views/pages/settings/links.php'] as $path) {
            self::assertStringContainsString('pa_resolver_link_visibility_sql()', file_get_contents($root . '/' . $path));
        }
    }

    private function invoiceLinkIds(): array
    {
        $links = pa_invoice_links_query_for_context($this->pdo, null, 101, null, null, null);
        $ids = array_column($links, 'id');
        sort($ids);
        return $ids;
    }

    private function storedLinkIds(): array
    {
        return array_map('intval', $this->pdo->query('SELECT id FROM entity_links ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }
}
