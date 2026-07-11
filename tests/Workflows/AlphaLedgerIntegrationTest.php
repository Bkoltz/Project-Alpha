<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/alphaledger_integration.php';

use PHPUnit\Framework\TestCase;

final class AlphaLedgerIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testDocumentedContractRoutesAreRewrittenAndDispatched(): void
    {
        $htaccess = file_get_contents($this->root . '/public/.htaccess');
        $front = file_get_contents($this->root . '/public/index.php');
        $controller = file_get_contents($this->root . '/src/controllers/api/alphaledger_integration.php');

        foreach (['manifest', 'installations', 'changes', 'reconciliation', 'time-records/batch', 'pay-accruals/batch', 'ledger-records/batch'] as $resource) {
            self::assertStringContainsString($resource, (string) $htaccess . (string) $controller);
        }
        self::assertStringContainsString("\$page === 'api-v1-alphaledger'", (string) $front);
        self::assertStringContainsString("'alphaledger.sync'", file_get_contents($this->root . '/src/utils/api_scopes.php'));
        self::assertStringContainsString('pa_al_refresh_assignments', (string) $controller);
    }

    public function testMigrationProvidesDurabilityAndOwnershipTables(): void
    {
        $sql = file_get_contents($this->root . '/database/migrations/0034_alphaledger_integration.sql');
        foreach (['pa_integration_identity', 'alphaledger_policy', 'alphaledger_installations', 'alphaledger_events', 'alphaledger_idempotency', 'alphaledger_received_events', 'alphaledger_project_assignments', 'employee_pay_records', 'alphaledger_sync_conflicts'] as $table) {
            self::assertStringContainsString($table, (string) $sql);
        }
        self::assertStringContainsString('UNIQUE KEY uq_time_entries_external', (string) $sql);
        self::assertStringContainsString('UNIQUE KEY uq_al_idempotency_key', (string) $sql);
    }

    public function testSynchronizationRequiresExplicitPolicyAndDedicatedKey(): void
    {
        $api = file_get_contents($this->root . '/src/controllers/api/alphaledger_integration.php');
        $handler = file_get_contents($this->root . '/src/controllers/settings/alphaledger_handler.php');
        $settings = file_get_contents($this->root . '/src/views/pages/settings/alphaledger.php');

        self::assertStringContainsString("'integration_disabled'", (string) $api);
        self::assertStringContainsString('approved_api_key_id', (string) $api);
        self::assertStringContainsString('approved_callback_hash', (string) $api);
        self::assertStringContainsString('allow_unrestricted_key', (string) $api);
        self::assertStringContainsString("!== ['alphaledger.sync']", (string) $handler);
        self::assertStringContainsString('password_verify', (string) $handler);
        self::assertStringContainsString('TwoFactorAuth::verifyCode', (string) $handler);
        self::assertStringContainsString('alphaledger.webhook_secret_rotated', (string) $handler);
        self::assertStringContainsString('rate_limit_check', (string) $handler);
        self::assertStringContainsString('confirm_enable', (string) $settings);
        self::assertStringContainsString('confirm_unrestricted_key', (string) $handler);
        self::assertStringContainsString('Current 6-digit TOTP code', (string) $settings);
        self::assertStringContainsString("status='disabled'", file_get_contents($this->root . '/src/controllers/api_keys_revoke.php'));
        self::assertStringContainsString('approved_key_scope_changed', file_get_contents($this->root . '/src/controllers/api_keys_update.php'));
        self::assertStringContainsString('JOIN alphaledger_policy', file_get_contents($this->root . '/src/utils/alphaledger_integration.php'));
        foreach (['time_entry_create.php', 'time_entry_update.php', 'time_entry_delete.php', 'time_entry_start_timer.php', 'time_entry_stop_timer.php'] as $controllerName) {
            self::assertStringContainsString('pa_al_block_local_time_mutation_when_enabled', file_get_contents($this->root . '/src/controllers/time-tracking/' . $controllerName));
        }
        self::assertStringContainsString('AlphaLedger-owned time entries can only be corrected in AlphaLedger', file_get_contents($this->root . '/src/controllers/time-tracking/time_entry_update.php'));
        self::assertStringContainsString('COALESCE(source_system', file_get_contents($this->root . '/src/controllers/time-tracking/time_entry_delete.php'));
    }

    public function testSignatureMatchesAlphaLedgerTimestampDotBodyContract(): void
    {
        $timestamp = '2026-07-11T12:00:00Z';
        $body = '{"event_id":"example"}';
        $secret = 'shared-secret';
        self::assertSame(hash_hmac('sha256', $timestamp . '.' . $body, $secret), pa_al_webhook_signature($timestamp, $body, $secret));
    }

    public function testEnvelopeValidationAcceptsVersionOneAndRejectsWrongInstallation(): void
    {
        $event = [
            'schema_version' => '1.0',
            'event_id' => '95f65e4f-7f39-4ba1-8815-f55c9b793f91',
            'event_type' => 'time_entry.approved',
            'occurred_at' => '2026-07-11T12:00:00Z',
            'installation_id' => 'install-1',
            'aggregate_id' => 'entry-1',
            'revision' => 1,
            'currency' => 'USD',
            'data' => [],
        ];
        pa_al_assert_envelope($event, ['time_entry.approved'], 'install-1');
        $this->addToAssertionCount(1);

        $this->expectException(DomainException::class);
        pa_al_assert_envelope($event, ['time_entry.approved'], 'install-2');
    }

    public function testCallbackValidationRequiresHttpsByDefault(): void
    {
        $previous = getenv('ALPHALEDGER_ALLOW_HTTP_CALLBACKS');
        putenv('ALPHALEDGER_ALLOW_HTTP_CALLBACKS=false');
        try {
            self::assertSame('https://ledger.example/api/v1/integrations/pa/events', pa_al_validate_callback_url('https://ledger.example/api/v1/integrations/pa/events'));
            $this->expectException(DomainException::class);
            pa_al_validate_callback_url('http://ledger.example/api/v1/integrations/pa/events');
        } finally {
            $previous === false ? putenv('ALPHALEDGER_ALLOW_HTTP_CALLBACKS') : putenv('ALPHALEDGER_ALLOW_HTTP_CALLBACKS=' . $previous);
        }
    }

    public function testOperationalLedgerUsesDirectionBoundSignature(): void
    {
        require_once $this->root . '/src/utils/alphaledger_ledger.php';
        $timestamp='2026-07-11T12:00:00Z';$raw='{"records":[]}';$secret='shared-secret';
        $expected=hash_hmac('sha256',$timestamp."\nPOST\n".PA_AL_LEDGER_PATH."\n".hash('sha256',$raw),$secret);
        self::assertSame($expected,pa_al_ledger_signature($timestamp,'POST',PA_AL_LEDGER_PATH,$raw,$secret));
        self::assertNotSame($expected,pa_al_ledger_signature($timestamp,'GET',PA_AL_LEDGER_PATH,$raw,$secret));
    }

    public function testOperationalLedgerUiIsAdminOnlyAndAccountingIsSeparate(): void
    {
        $ledger=(string)file_get_contents($this->root.'/src/views/pages/financial/ledger.php');
        $header=(string)file_get_contents($this->root.'/src/views/partials/header.php');
        $dashboard=(string)file_get_contents($this->root.'/src/views/pages/financial/financial-dashboard.php');
        self::assertStringContainsString("!== 'admin'",$ledger);
        self::assertStringContainsString('financial/ledger',$header);
        self::assertStringContainsString('Not included in Income, Expenses, or Net Profit',$dashboard);
        self::assertStringNotContainsString('$netProfit -',$dashboard);
    }

    public function testOperationalLedgerMigrationProvidesNormalizedReadModel(): void
    {
        $sql=(string)file_get_contents($this->root.'/database/migrations/0035_alphaledger_operational_ledger.sql');
        foreach(['alphaledger_ledger_people','alphaledger_ledger_projects','alphaledger_ledger_assignments','alphaledger_ledger_time_entries','alphaledger_ledger_breaks','alphaledger_ledger_revisions','alphaledger_ledger_snapshots']as$table)self::assertStringContainsString($table,$sql);
        self::assertStringContainsString('last_ledger_sync_at',$sql);
        self::assertStringContainsString('external_employee_id',$sql);
    }
}
