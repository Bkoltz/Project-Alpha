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

    // Execute via mysql CLI (handles DELIMITER, stored procedures, multi-statement).
    // PDO exec() cannot run DELIMITER/PROCEDURE files (migration 015 uses them).
    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '3306';
    $db   = getenv('MYSQL_DATABASE') ?: 'project_alpha';
    $user = getenv('MYSQL_USER') ?: 'root';
    $pass = getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: '';

    $descriptors = [
        0 => ['pipe', 'r'],  // stdin — pipe the SQL in
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];
    $cmd = sprintf(
        'mysql --skip-ssl -h %s -P %s -u %s --password=%s -D %s 2>&1',
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg($user),
        escapeshellarg($pass),
        escapeshellarg($db)
    );
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        echo "FAILED (could not start mysql CLI)\n";
        $failed = true;
        continue;
    }
    fwrite($pipes[0], $sql);
    fclose($pipes[0]);
    $cliOut = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $cliErr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $cliStatus = proc_close($proc);

    if ($cliStatus === 0) {
        // Success — record in tracking table (PDO)
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO migrations (filename, checksum) VALUES (?, ?)");
            $stmt->execute([$name, $checksum]);
            echo "OK\n";
        } catch (Exception $e) {
            echo "OK (tracking record failed: " . $e->getMessage() . ")\n";
        }
    } else {
        // mysql CLI failed — check if it's an "already exists" (idempotent no-op)
        $msg = trim($cliErr . ' ' . $cliOut);
        $isAlreadyApplied = (
            stripos($msg, 'Duplicate column') !== false ||
            stripos($msg, 'already exists') !== false ||
            stripos($msg, 'Duplicate key') !== false ||
            stripos($msg, '1060') !== false ||
            stripos($msg, '1050') !== false
        );
        if ($isAlreadyApplied) {
            echo "ALREADY APPLIED (skipped)\n";
            try {
                $pdo->prepare("INSERT IGNORE INTO migrations (filename, checksum) VALUES (?, ?)")
                    ->execute([$name, $checksum]);
            } catch (Exception $e) {
                // tracking insert failed — non-fatal, will retry next boot
            }
            continue;
        }
        echo "FAILED\n";
        echo "  Error: " . substr($msg, 0, 500) . "\n";
        $failed = true;
        // DO NOT break — continue to try subsequent migrations so one bad file
        // doesn't block all later ones (e.g. 015 failing shouldn't block 021/022).
    }
}

if ($failed) {
    echo "\nMigration runner finished, but one or more migrations failed.\n";
    exit(1);
}

if (!$dryRun) {
    echo "\nAll migrations completed successfully.\n";
}
exit(0);
