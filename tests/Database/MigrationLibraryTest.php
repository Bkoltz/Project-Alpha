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
}
