<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';

use PHPUnit\Framework\TestCase;

final class MigrationLibraryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pa-migrations-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testEmptyBaselineHasNoPendingFiles(): void
    {
        $this->assertSame([], migration_files($this->directory));
    }

    public function testSequentialFilesAndChecksumsAreLoaded(): void
    {
        file_put_contents($this->directory . '/0001_add_widget.sql', "CREATE TABLE widget (id INT);\n");
        file_put_contents($this->directory . '/0002_add_widget_name.sql', "ALTER TABLE widget ADD name VARCHAR(50);\n");

        $files = migration_files($this->directory);

        $this->assertSame([1, 2], array_keys($files));
        $this->assertSame(hash('sha256', "CREATE TABLE widget (id INT);\n"), $files[1]['checksum']);
    }

    public function testSequenceGapsFailValidation(): void
    {
        file_put_contents($this->directory . '/0002_skipped_first.sql', 'SELECT 1;');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not contiguous');
        migration_files($this->directory);
    }

    public function testRollbackFilesAreRejected(): void
    {
        file_put_contents($this->directory . '/0001_widget_rollback.sql', 'DROP TABLE widget;');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rollback migration');
        migration_files($this->directory);
    }

    public function testAppliedChecksumChangesFailValidation(): void
    {
        file_put_contents($this->directory . '/0001_add_widget.sql', 'SELECT 1;');
        $files = migration_files($this->directory);
        $ledger = [
            0 => ['version' => 0, 'filename' => 'baseline.sql', 'checksum' => null],
            1 => ['version' => 1, 'filename' => '0001_add_widget.sql', 'checksum' => str_repeat('0', 64)],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum mismatch');
        migration_validate_history($files, $ledger);
    }

    public function testAppliedChecksumAcceptsLineEndingOnlyChanges(): void
    {
        $lfSql = "CREATE TABLE widget (id INT);\nALTER TABLE widget ADD name VARCHAR(50);\n";
        $crlfSql = str_replace("\n", "\r\n", $lfSql);
        file_put_contents($this->directory . '/0001_add_widget.sql', $crlfSql);
        $files = migration_files($this->directory);
        $ledger = [
            0 => ['version' => 0, 'filename' => 'baseline.sql', 'checksum' => null],
            1 => ['version' => 1, 'filename' => '0001_add_widget.sql', 'checksum' => hash('sha256', $lfSql)],
        ];

        migration_validate_history($files, $ledger);

        $this->assertSame(hash('sha256', $crlfSql), $files[1]['checksum']);
        $this->assertContains(hash('sha256', $lfSql), $files[1]['checksums']);
    }

    public function testSqlSplitterPreservesQuotedSemicolons(): void
    {
        $statements = migration_statements(
            "-- comment\nINSERT INTO example (value) VALUES ('one;two');\nSELECT `semi;colon` FROM example;"
        );

        $this->assertCount(2, $statements);
        $this->assertStringContainsString("'one;two'", $statements[0]);
        $this->assertStringContainsString('`semi;colon`', $statements[1]);
    }

    public function testSchemaHealthRequirementsFollowTheAppliedMigrationVersion(): void
    {
        $tables = migration_required_tables_for_version([
            'users', 'pricing_adjustment_definitions', 'contract_settlement_terms',
        ], 71);
        $this->assertSame(['users'], $tables);
        $this->assertContains('pricing_adjustment_definitions', migration_required_tables_for_version([
            'pricing_adjustment_definitions', 'contract_settlement_terms',
        ], 72));
        $this->assertSame(
            ['pricing_adjustment_definitions', 'contract_settlement_terms'],
            migration_required_tables_for_version([
                'pricing_adjustment_definitions', 'contract_settlement_terms',
            ], 77)
        );
        $this->assertSame(
            ['pricing_adjustment_definitions', 'contract_settlement_terms'],
            migration_required_tables_for_version([
                'pricing_adjustment_definitions', 'contract_settlement_terms',
                'portal_service_assignment_projection_receipts',
            ], 79)
        );
        $this->assertContains('portal_service_assignment_projection_receipts', migration_required_tables_for_version([
            'portal_service_assignment_projection_receipts',
        ], 80));

        $columns = [
            'invoices' => ['organization_id', 'generation_key'],
            'pricing_adjustment_definitions' => ['organization_id', 'scope_type'],
            'contract_settlement_terms' => ['organization_id'],
            'portal_integration_profiles' => ['service_assignment_projection_enabled'],
        ];
        $this->assertSame(
            ['invoices' => ['organization_id']],
            migration_required_columns_for_version($columns, 71)
        );
        $this->assertSame(
            ['invoices' => ['organization_id'], 'pricing_adjustment_definitions' => ['organization_id']],
            migration_required_columns_for_version($columns, 72)
        );
        $through79 = $columns;
        unset($through79['portal_integration_profiles']);
        $this->assertSame($through79, migration_required_columns_for_version($columns, 79));
        $this->assertSame($columns, migration_required_columns_for_version($columns, 80));
    }
}
