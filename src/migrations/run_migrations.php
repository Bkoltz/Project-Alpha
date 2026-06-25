<?php
/**
 * Migration Runner for Project Alpha
 *
 * Usage: php src/migrations/run_migrations.php [--dry-run] [--verbose]
 *
 * Reads all .sql files from database/migrations/ sorted by filename, skips
 * ones already recorded in the schema_migrations tracking table, and runs each
 * new migration with per-file error handling.
 *
 * IMPORTANT: MySQL DDL statements (CREATE TABLE, ALTER TABLE) implicitly commit
 * and CANNOT be wrapped in transactions. This runner executes each file's SQL
 * directly without beginTransaction/commit. DELIMITER statements (a mysql CLI
 * construct, not SQL) are stripped and the file is split into individual
 * statements for PDO execution.
 *
 * Failures are logged to stderr and to PHP's error_log(), then execution
 * continues so that the container can still start Apache.
 */

require_once __DIR__ . '/../config/db.php';

$dryRun  = in_array('--dry-run', $argv, true);
$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);

// Safety valve: skip migrations entirely if SKIP_MIGRATIONS_ON_BOOT is set.
// This allows the app to start even if a migration is broken, providing a
// recovery path without requiring a code rollback.
if (filter_var(getenv('SKIP_MIGRATIONS_ON_BOOT') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "[migrations] SKIP_MIGRATIONS_ON_BOOT is set — skipping all migrations.\n");
    error_log('[migrations] SKIP_MIGRATIONS_ON_BOOT is set — skipping all migrations.');
    exit(0);
}

$migrationsDir = dirname(__DIR__, 2) . '/database/migrations';
// Exclude deprecated files AND rollback files (rollback files must never auto-run)
$excluded = ['000_all_DEPRECATED.sql'];
$excludePatterns = ['/_rollback\.sql$/'];

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
        // NO multi-statement — we execute statements one at a time
        // Buffer all queries so no unbuffered result sets block execution
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
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
 * Seed schema_migrations with ALL migration files that exist on disk but
 * aren't in the tracking table yet, IF the database already has data (i.e.
 * it's an existing DB, not a fresh install). This prevents legacy non-
 * idempotent migrations from running on existing databases.
 *
 * Heuristic: if the `quotes` table exists and has rows in `schema_migrations`
 * is empty, we assume the DB was created from init.sql and all migrations
 * up to the current set have already been applied implicitly.
 */
function seed_existing_migrations_for_existing_db(PDO $pdo, array $allFiles): void
{
    // Only seed if schema_migrations is empty
    $count = (int)$pdo->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();
    if ($count > 0) {
        return;
    }

    // Check if this is an existing DB (has the quotes table = init.sql was applied)
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'quotes'");
        if (!$check || $check->rowCount() === 0) {
            return; // Fresh DB — let migrations run normally
        }
    } catch (Throwable $e) {
        return;
    }

    // Existing DB with empty schema_migrations: seed all migration files
    // as "already applied" EXCEPT migrations 023+ (the ACL migrations).
    // Migrations 023 and 024 add the ACL schema (roles, role_permissions,
    // user_permissions_overrides, created_by columns) which are NOT in the
    // old init.sql. They must be allowed to run so the ACL tables get created.
    // Migrations before 023 were already incorporated into init.sql, so
    // marking them as "applied" prevents non-idempotent legacy migrations
    // from re-running and failing.
    $insert = $pdo->prepare("INSERT IGNORE INTO schema_migrations (filename, checksum) VALUES (?, ?)");
    $seededCount = 0;
    foreach ($allFiles as $file) {
        $name = basename($file);
        // Skip ACL migrations (023+) — they must be allowed to run. Only
        // migrations numbered below 023 are seeded as already-applied.
        if (preg_match('/^(\d+)_/', $name, $m) && (int)$m[1] >= 23) {
            continue;
        }
        $sql = @file_get_contents($file);
        $checksum = $sql !== false ? hash('sha256', $sql) : null;
        $insert->execute([$name, $checksum]);
        $seededCount++;
    }
    if ($verbose ?? false) {
        echo "Seeded schema_migrations with {$seededCount} legacy migration files (existing DB detected). ACL migrations (023+) will run.\n";
    }
}

/**
 * Strip DELIMITER statements (a mysql CLI construct not supported by PDO)
 * and split the SQL into individual statements that can be executed via PDO::exec().
 *
 * Handles stored procedures: when a DELIMITER // block is found, the statements
 * between the delimiters are joined back into a single statement (the CREATE PROCEDURE
 * body) so PDO can execute it as one unit.
 */
function parse_sql_for_pdo(string $sql): array
{
    $statements = [];
    $lines = preg_split('/\r\n|\r|\n/', $sql);
    $currentDelimiter = ';';
    $currentStatement = '';
    $inDelimiterBlock = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Detect DELIMITER change (mysql CLI construct)
        if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmed, $m)) {
            if ($inDelimiterBlock && $currentStatement !== '') {
                // End of previous delimiter-block statement
                $statements[] = trim($currentStatement);
                $currentStatement = '';
            }
            $currentDelimiter = trim($m[1]);
            $inDelimiterBlock = ($currentDelimiter !== ';');
            continue;
        }

        // Skip comments and empty lines (but keep comment lines inside procedure bodies)
        if (!$inDelimiterBlock && ($trimmed === '' || str_starts_with($trimmed, '--'))) {
            if ($currentStatement !== '' && str_ends_with(trim($currentStatement), $currentDelimiter)) {
                $statements[] = trim($currentStatement);
                $currentStatement = '';
            }
            continue;
        }

        $currentStatement .= $line . "\n";

        // Check if the current line ends with the delimiter
        if (str_ends_with(rtrim($trimmed), $currentDelimiter)) {
            // Remove the trailing delimiter from the statement
            $stmt = rtrim($currentStatement);
            $stmt = substr($stmt, 0, -strlen($currentDelimiter));
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $currentStatement = '';
        }
    }

    // Catch any remaining statement
    if (trim($currentStatement) !== '') {
        $statements[] = trim($currentStatement);
    }

    return $statements;
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
    if (preg_match('/\b(?:CREATE|ALTER|DROP|INSERT|UPDATE|DELETE|TRUNCATE|RENAME|USE|SET|GRANT|REVOKE|CALL)\b/i', $sql) === 0
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
// Collect ALL migration files (for seeding check)
// ---------------------------------------------------------------------------
$allFiles = glob($migrationsDir . '/*.sql');
if ($allFiles === false) {
    $allFiles = [];
}
usort($allFiles, static function ($a, $b) {
    return strcmp(basename($a), basename($b));
});

// Filter out excluded files and rollback files
$runnableFiles = [];
foreach ($allFiles as $file) {
    $name = basename($file);
    if (in_array($name, $excluded, true)) {
        continue;
    }
    $isRollback = false;
    foreach ($excludePatterns as $pattern) {
        if (preg_match($pattern, $name)) {
            $isRollback = true;
            break;
        }
    }
    if ($isRollback) {
        continue;
    }
    $runnableFiles[] = $file;
}

// Seed existing migrations for existing DBs (prevents legacy non-idempotent re-runs)
seed_existing_migrations_for_existing_db($pdo, $runnableFiles);

// ---------------------------------------------------------------------------
// Load already-applied migrations
// ---------------------------------------------------------------------------
$appliedMap = [];
$stmt = $pdo->query("SELECT filename FROM schema_migrations");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $appliedMap[$row['filename']] = true;
}
$stmt->closeCursor();

// ---------------------------------------------------------------------------
// REPAIR: If ACL migrations (023/024/025) are marked "applied" but the ACL
// tables don't actually exist (happened due to a buggy seed function that
// marked ALL migrations as applied on existing DBs), remove them from the
// applied list and delete their schema_migrations entries so they re-run.
// 025 is included so its role_id/created_by backfills re-apply too.
// ---------------------------------------------------------------------------
$aclTablesOk = true;
try {
    $check = $pdo->query("SHOW TABLES LIKE 'role_permissions'");
    if (!$check || $check->rowCount() === 0) {
        $aclTablesOk = false;
    }
    $check->closeCursor();
} catch (Throwable $e) {
    $aclTablesOk = false;
}

if (!$aclTablesOk) {
    $aclMigrations = ['023_role_permissions.sql', '024_add_created_by_columns.sql', '025_acl_round3_fixes.sql'];
    $repaired = [];
    foreach ($aclMigrations as $mname) {
        if (isset($appliedMap[$mname])) {
            unset($appliedMap[$mname]);
            $repaired[] = $mname;
        }
    }
    if (!empty($repaired)) {
        if ($verbose) {
            echo "REPAIR: ACL tables missing but migrations marked as applied. Re-queueing: " . implode(', ', $repaired) . "\n";
        }
        $del = $pdo->prepare("DELETE FROM schema_migrations WHERE filename = ?");
        foreach ($repaired as $mname) {
            $del->execute([$mname]);
        }
    }
}

// ---------------------------------------------------------------------------
// Collect pending migration files
// ---------------------------------------------------------------------------
$pending = [];
foreach ($runnableFiles as $file) {
    $name = basename($file);
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

// Clear any unbuffered result sets on the main $pdo connection
// (left over from the seed function's queries)
try {
    $stmt = $pdo->query('SELECT 1');
    $stmt->closeCursor();
} catch (Throwable $e) {
    // ignore
}

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
            . ($verbose ? ' (' . substr($checksum, 0, 12) . ')' : '')
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
    // Live run: parse SQL (strip DELIMITER, split statements) and execute
    // WITHOUT transaction wrapping (MySQL DDL auto-commits anyway)
    // -----------------------------------------------------------------------
    try {
        $statements = parse_sql_for_pdo($sql);
        foreach ($statements as $stmtSql) {
            $stmtSql = trim($stmtSql);
            if ($stmtSql === '' || str_starts_with($stmtSql, '--')) {
                continue;
            }
            // Use query() instead of exec() so we can drain result sets
            // (statements like SET @x := (SELECT ...) return a result set
            // that blocks the next query if not consumed)
            $result = $execPdo->query($stmtSql);
            if ($result !== false) {
                $result->closeCursor();
            }
        }

        // Record the migration using the exec PDO (avoids unbuffered query
        // conflicts on the main $pdo connection)
        $record = $execPdo->prepare("INSERT INTO schema_migrations (filename, checksum) VALUES (?, ?)");
        $record->execute([$name, $checksum]);

        if ($verbose) {
            echo "OK\n";
        }
    } catch (Throwable $e) {
        log_migration_error("Migration '$name' failed: " . $e->getMessage());

        // Still record that we attempted it so it doesn't re-run on every boot
        try {
            $record = $execPdo->prepare("INSERT IGNORE INTO schema_migrations (filename, checksum) VALUES (?, ?)");
            $record->execute([$name, $checksum]);
        } catch (Throwable $ignore) {}

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