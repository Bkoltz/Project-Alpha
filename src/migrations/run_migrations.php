<?php
/**
 * Migration Runner for Project Alpha
 *
 * Usage: php src/migrations/run_migrations.php [--dry-run] [--verbose]
 *
 * Reads all .sql files from database/migrations/ in numeric order,
 * skips ones already recorded in the migrations tracking table.
 * Runs each new migration inside a transaction.
 */

require_once __DIR__ . '/../config/db.php';

$dryRun = in_array('--dry-run', $argv);
$verbose = in_array('--verbose', $argv) || in_array('-v', $argv);

$migrationsDir = dirname(__DIR__, 2) . '/database/migrations';
$excluded = ['000_all_DEPRECATED.sql'];

echo "=== Project Alpha Migration Runner ===\n";
echo "Mode: " . ($dryRun ? "DRY-RUN" : "LIVE") . "\n\n";

// Ensure migrations tracking table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    checksum VARCHAR(64) NULL,
    INDEX idx_migrations_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Get already-run migrations
$runMap = [];
foreach ($pdo->query("SELECT filename FROM migrations") as $row) {
    $runMap[$row['filename']] = true;
}

// Collect migration files
$files = glob($migrationsDir . '/*.sql');
usort($files, function ($a, $b) {
    return strcmp(basename($a), basename($b));
});

$pending = [];
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $excluded)) continue;
    if (isset($runMap[$name])) continue;
    $pending[] = $file;
}

usort($pending, function ($a, $b) {
    return strcmp(basename($a), basename($b));
});

if (empty($pending)) {
    echo "No pending migrations. All up to date.\n";
    exit(0);
}

echo "Pending migrations (" . count($pending) . "):\n";
foreach ($pending as $file) {
    echo "  " . basename($file) . "\n";
}
echo "\n";

$failed = false;
foreach ($pending as $file) {
    $name = basename($file);
    $sql = file_get_contents($file);
    $checksum = hash('sha256', $sql);

    if ($dryRun) {
        echo "[DRY-RUN] Would run: $name\n";
        continue;
    }

    echo "Running: $name ... ";

    $pdo->beginTransaction();
    try {
        // MySQL PDO doesn't support multiple statements via exec by default,
        // so we split on semicolons but preserve triggers/procedures
        $statements = preg_split('/;\s*\n(?=\s*(?:CREATE|ALTER|INSERT|UPDATE|DELETE|DROP|USE|SET|GRANT|REVOKE|FLUSH|OPTIMIZE|ANALYZE|CHECK|REPAIR|TRUNCATE|CALL|DO|HANDLER|LOAD|REPLACE|SHOW|DESCRIBE|EXPLAIN|HELP|USE|LOCK|UNLOCK|START|BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE|CHAIN|XA|PREPARE|EXECUTE|DEALLOCATE|SET|GET|SHOW|DECLARE|IF|CASE|LOOP|WHILE|REPEAT|LEAVE|ITERATE|RETURN|SIGNAL|RESIGNAL|UNTIL|OPEN|FETCH|CLOSE|CURSOR|CONTINUE|EXIT|UNDO|SQLSTATE|CONDITION|HANDLER|FORCE|IGNORE|QUICK|CONCURRENT|NO_WRITE_TO_BINLOG|LOCAL|LOW_PRIORITY|DELAYED|HIGH_PRIORITY|SQL_SMALL_RESULT|SQL_BIG_RESULT|SQL_BUFFER_RESULT|SQL_CACHE|SQL_NO_CACHE|SQL_CALC_FOUND_ROWS|STRAIGHT_JOIN|FOR_UPDATE|LOCK_IN_SHARE_MODE|INTO|OUTFILE|DUMPFILE|CHARACTER|SET|NAMES|COLLATE|FROM|DATABASE|SCHEMA|TABLE|INDEX|VIEW|EVENT|TRIGGER|FUNCTION|PROCEDURE|SERVER|PLUGIN|USER|LOGFILE_GROUP|TABLESPACE|PARTITION|COLUMN|SPATIAL|FULLTEXT|UNIQUE|PRIMARY|FOREIGN|KEY|CONSTRAINT|DEFAULT|AUTO_INCREMENT|CHECK|NOT|NULL|UNSIGNED|ZEROFILL|BINARY|CHARACTER|SET|COLLATE|COMMENT|ON|UPDATE|DELETE|CASCADE|SET|NULL|NO|ACTION|RESTRICT|RESTRICT|DEFINER|INVOKER|SQL|SECURITY|DETERMINISTIC|CONTAINS|SQL|READS|SQL|DATA|MODIFIES|SQL|DATA|LANGUAGE|SQL|EXTERNAL|NAME|PARAMETER|RETURNS|AGGREGATE|SONAME|SHARE|MODE|NOWAIT|WAIT|SKIP|LOCKED|OF|NOWAIT|WAIT|SKIP|LOCKED))/s', $sql);

        // Simpler approach: just exec the whole file
        // This works if we use PDO with proper multi-statement support
        // Actually let's just use exec
        $pdo->exec($sql);

        $stmt = $pdo->prepare("INSERT INTO migrations (filename, checksum) VALUES (?, ?)");
        $stmt->execute([$name, $checksum]);

        $pdo->commit();
        echo "OK\n";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "FAILED\n";
        echo "  Error: " . $e->getMessage() . "\n";
        $failed = true;
        break;
    }
}

if ($failed) {
    echo "\nMigration failed. Database rolled back.\n";
    exit(1);
}

if (!$dryRun) {
    echo "\nAll migrations completed successfully.\n";
}
exit(0);
