<?php
/**
 * Migration Runner for Project Alpha
 *
 * Usage: php src/migrations/run_migrations.php [--dry-run] [--verbose]
 *
 * Reads all .sql files from database/migrations/ sorted by filename, skips
 * ones already recorded in the schema_migrations tracking table, and runs each
 * new migration inside a transaction with per-file error handling.
 *
 * Failures are logged to stderr and to PHP's error_log(), then execution
 * continues so that the container can still start Apache.
 */

require_once __DIR__ . '/../config/db.php';

$dryRun  = in_array('--dry-run', $argv, true);
$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);

$migrationsDir = dirname(__DIR__, 2) . '/database/migrations';
$excluded = ['000_all_DEPRECATED.sql'];

/**
 * Log a message to stderr and to PHP's error log.
 */
function log_migration_error(string $message): void
{
    $line = '[' . date('c') . '] ' . $message;
    fwrite(STDERR, $line . PHP_EOL);
    error_log($line);
}

/**
 * Build a PDO connection that explicitly enables multi-statement execution.
 */
function create_multi_statement_pdo(): PDO
{
    $host = getenv('DB_HOST') ?: 'db';
    $db   = getenv('MYSQL_DATABASE') ?: 'project_alpha';
    $user = getenv('MYSQL_USER') ?: 'root';
    $pass = getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: 'rootpass';

    $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ];

    return new PDO($dsn, $user, $pass, $options);
}

/**
 * One-time seed: copy already-applied migrations from the legacy
 * `migrations` table (if it exists) into the new `schema_migrations`
 * table so legacy non-idempotent files are not re-run.
 */
function seed_from_legacy_migrations_table(PDO $pdo): void
{
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'migrations'");
        if (!$check || $check->rowCount() === 0) {
            return;
        }

        $rows = $pdo->query("SELECT filename, checksum FROM migrations")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return;
        }

        $insert = $pdo->prepare("INSERT IGNORE INTO schema_migrations (filename, checksum) VALUES (?, ?)");
        foreach ($rows as $row) {
            $insert->execute([
                $row['filename'],
                $row['checksum'] ?? null,
            ]);
        }
    } catch (Throwable $e) {
        // Non-fatal: if the legacy table cannot be read we simply continue.
        log_migration_error('Legacy migration seeding skipped: ' . $e->getMessage());
    }
}

/**
 * Lightweight dry-run validation: ensure the file is readable, non-empty,
 * valid UTF-8, and contains at least one SQL statement token.
 */
function migration_sql_looks_valid(string $sql): bool
{
    $trimmed = trim($sql);
    if ($trimmed === '') {
        return false;
    }

    if (function_exists('mb_check_encoding')) {
        if (!mb_check_encoding($sql, 'UTF-8')) {
            return false;
        }
    } elseif (preg_match('//u', $sql) === false) {
        return false;
    }

    // Require at least one recognised SQL statement keyword or a semicolon.
    if (preg_match('/\b(?:CREATE|ALTER|DROP|INSERT|UPDATE|DELETE|TRUNCATE|RENAME|USE|SET|GRANT|REVOKE)\b/i', $sql) === 0
        && strpos($sql, ';') === false
    ) {
        return false;
    }

    return true;
}

// ---------------------------------------------------------------------------
// Ensure tracking table exists
// ---------------------------------------------------------------------------
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    checksum VARCHAR(64) NULL,
    INDEX idx_sm_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

seed_from_legacy_migrations_table($pdo);

// ---------------------------------------------------------------------------
// Load already-applied migrations
// ---------------------------------------------------------------------------
$appliedMap = [];
$stmt = $pdo->query("SELECT filename FROM schema_migrations");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $appliedMap[$row['filename']] = true;
}

// ---------------------------------------------------------------------------
// Collect pending migration files
// ---------------------------------------------------------------------------
$files = glob($migrationsDir . '/*.sql');
if ($files === false) {
    $files = [];
}

usort($files, static function ($a, $b) {
    return strcmp(basename($a), basename($b));
});

$pending = [];
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $excluded, true)) {
        continue;
    }
    if (isset($appliedMap[$name])) {
        continue;
    }
    $pending[] = $file;
}

if (empty($pending)) {
    if ($verbose) {
        echo "No pending migrations. All up to date.\n";
    }
    exit(0);
}

echo "=== Project Alpha Migration Runner ===\n";
echo "Mode: " . ($dryRun ? "DRY-RUN" : "LIVE") . "\n";
echo "Pending migrations (" . count($pending) . "):\n";
foreach ($pending as $file) {
    echo "  " . basename($file) . "\n";
}
echo "\n";

// Dedicated PDO for executing multi-statement migration SQL.
$execPdo = create_multi_statement_pdo();

$anyFailed = false;

foreach ($pending as $file) {
    $name = basename($file);
    $sql = @file_get_contents($file);

    if ($sql === false) {
        log_migration_error("Migration file '$name' could not be read.");
        $anyFailed = true;
        continue;
    }

    $checksum = hash('sha256', $sql);

    // -----------------------------------------------------------------------
    // Dry run: validate but do not execute
    // -----------------------------------------------------------------------
    if ($dryRun) {
        $valid = migration_sql_looks_valid($sql);
        echo '[DRY-RUN] ' . $name
            . ($verbose ? ' (' . $checksum . ')' : '')
            . ' - ' . ($valid ? 'OK (would apply)' : 'INVALID / EMPTY')
            . "\n";
        if (!$valid) {
            $anyFailed = true;
        }
        continue;
    }

    if ($verbose) {
        echo "Running: $name ... ";
    }

    // -----------------------------------------------------------------------
    // Live run: execute in a transaction with per-file try/catch
    // -----------------------------------------------------------------------
    try {
        $execPdo->beginTransaction();
        $execPdo->exec($sql);
        $execPdo->commit();

        $record = $pdo->prepare("INSERT INTO schema_migrations (filename, checksum) VALUES (?, ?)");
        $record->execute([$name, $checksum]);

        if ($verbose) {
            echo "OK\n";
        }
    } catch (Throwable $e) {
        if ($execPdo->inTransaction()) {
            $execPdo->rollBack();
        }

        log_migration_error("Migration '$name' failed: " . $e->getMessage());

        if ($verbose) {
            echo "FAILED\n";
            echo "  Error: " . $e->getMessage() . "\n";
        }

        $anyFailed = true;
        continue;
    }
}

if ($dryRun) {
    echo "\nDry-run complete. "
        . ($anyFailed ? "Some pending migrations look invalid." : "All pending migrations look valid.")
        . "\n";
} else {
    echo "\nMigration run complete. "
        . ($anyFailed ? "One or more migrations failed; the application will still start." : "All migrations applied successfully.")
        . "\n";
}

exit(0);
