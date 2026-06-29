<?php
/**
 * Pure PHP database backup script (avoids SSL issues with mysqldump)
 * Creates timestamped SQL dump to /var/www/backups/
 * Retention: 7 daily, 4 weekly, 12 monthly
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/cron_state.php';
require_once __DIR__ . '/../utils/backup_archive.php';

$jobName = 'backup_database';

$scheduledRun = in_array('--scheduled', $argv ?? [], true);
if ($scheduledRun) {
    $backupHour = 2;
    try {
        $hourStmt = $pdo->prepare('SELECT config_value FROM app_config WHERE organization_id=0 AND config_key="backup_hour"');
        $hourStmt->execute();
        $configuredHour = $hourStmt->fetchColumn();
        if ($configuredHour !== false) {
            $backupHour = max(0, min(23, (int)$configuredHour));
        }
    } catch (Throwable $e) {
        // Use the safe default when settings are unavailable.
    }
    if ((int)date('G') !== $backupHour) {
        exit(0);
    }
}

$backupDir = '/var/www/backups';
$dailyDir = $backupDir . '/daily';
$weeklyDir = $backupDir . '/weekly';
$monthlyDir = $backupDir . '/monthly';

foreach ([$dailyDir, $weeklyDir, $monthlyDir] as $d) {
    if (!is_dir($d)) mkdir($d, 0750, true);
}

$todayArtifacts = array_merge(
    glob($dailyDir . '/' . (getenv('MYSQL_DATABASE') ?: 'project_alpha') . '_' . date('Y-m-d') . '_*.sql.gz') ?: [],
    glob($dailyDir . '/' . (getenv('MYSQL_DATABASE') ?: 'project_alpha') . '_' . date('Y-m-d') . '_*.zip') ?: []
);
if ($scheduledRun && $todayArtifacts) {
    cron_state_mark_success($pdo, $jobName, 'Scheduled backup already exists for today');
    exit(0);
}

$db = getenv('MYSQL_DATABASE') ?: 'project_alpha';
$date = date('Y-m-d_H-i-s');
$filename = $db . '_' . $date . '.sql.gz';
$filepath = $dailyDir . '/' . $filename;

// Open gzip stream for writing
$gz = gzopen($filepath, 'wb9');
if (!$gz) {
    @error_log("[Backup] FAILED: Could not open $filepath for writing");
    cron_state_mark_failure($pdo, $jobName, new RuntimeException("Could not open {$filepath} for writing"));
    exit(1);
}

// Write header
gzwrite($gz, "-- Project Alpha Database Backup\n");
gzwrite($gz, "-- Generated: " . date('Y-m-d H:i:s') . " UTC\n");
gzwrite($gz, "-- Database: $db\n\n");
gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n");
gzwrite($gz, "SET NAMES utf8mb4;\n\n");

// Get all tables
$tables = [];
$stmt = $pdo->query("SHOW TABLES");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $tables[] = $row[0];
}

foreach ($tables as $table) {
    // Table structure
    gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n");
    $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
    $create = $stmt->fetch(PDO::FETCH_ASSOC);
    gzwrite($gz, $create['Create Table'] . ";\n\n");
    
    // Table data
    $stmt = $pdo->query("SELECT * FROM `$table`");
    $firstRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($firstRow !== false) {
        gzwrite($gz, "INSERT INTO `$table` VALUES\n");
        $first = true;
        $row = $firstRow;
        do {
            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = $pdo->quote((string)$value);
                }
            }
            if (!$first) gzwrite($gz, ",\n");
            gzwrite($gz, '(' . implode(', ', $values) . ')');
            $first = false;
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } while ($row !== false);
        gzwrite($gz, ";\n\n");
    }
}

gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
gzclose($gz);

// Verify
if (!file_exists($filepath) || filesize($filepath) < 100) {
    @error_log("[Backup] FAILED: backup file too small or missing");
    if (file_exists($filepath)) unlink($filepath);
    cron_state_mark_failure($pdo, $jobName, new RuntimeException('Backup file too small or missing'));
    exit(1);
}

$backupMode = 'database';
try {
    $modeStmt = $pdo->prepare('SELECT config_value FROM app_config WHERE organization_id=0 AND config_key="backup_mode"');
    $modeStmt->execute();
    $configuredMode = (string)($modeStmt->fetchColumn() ?: 'database');
    $backupMode = $configuredMode === 'full' ? 'full' : 'database';
} catch (Throwable $e) {
    // Database-only remains the safe default.
}
$encryptionKey = backup_archive_key();
if ($backupMode === 'full' || $encryptionKey !== '') {
    $archiveSuffix = $backupMode === 'full' ? '.full.zip' : '.db.zip';
    $archiveFilename = $db . '_' . $date . $archiveSuffix;
    $archivePath = $dailyDir . '/' . $archiveFilename;
    try {
        backup_create_archive($filepath, $archivePath, $backupMode === 'full', $encryptionKey);
        if (!is_file($archivePath) || filesize($archivePath) < 100) {
            throw new RuntimeException('Backup archive is missing or empty.');
        }
        @unlink($filepath);
        $filename = $archiveFilename;
        $filepath = $archivePath;
    } catch (Throwable $e) {
        @unlink($archivePath);
        cron_state_mark_failure($pdo, $jobName, $e);
        @error_log('[Backup] Archive creation failed: ' . $e->getMessage());
        exit(1);
    }
}

$size = round(filesize($filepath) / 1024, 1);
@error_log("[Backup] SUCCESS: " . $filepath . " (" . $size . "KB)");

// Weekly backup on Sundays
if (date('w') === '0') {
    $weeklyFile = $weeklyDir . '/' . $filename;
    copy($filepath, $weeklyFile);
    @error_log("[Backup] Weekly copy: " . $weeklyFile);
}

// Monthly backup on 1st
if (date('j') === '1') {
    $monthlyFile = $monthlyDir . '/' . $filename;
    copy($filepath, $monthlyFile);
    @error_log("[Backup] Monthly copy: " . $monthlyFile);
}

// Retention — configurable via BACKUP_RETENTION_DAYS env var
// Default: 10 daily backups. Set to 0 to disable retention (keep all).
$retentionDays = (int)(getenv('BACKUP_RETENTION_DAYS') ?: '10');
// Check if overridden in app_config DB
try {
    $cfgStmt = $pdo->prepare("SELECT config_value FROM app_config WHERE organization_id = 0 AND config_key = 'backup_retention_days'");
    $cfgStmt->execute();
    $cfgRow = $cfgStmt->fetch(PDO::FETCH_ASSOC);
    if ($cfgRow !== false) $retentionDays = (int)$cfgRow['config_value'];
} catch (Exception $e) {}
if ($retentionDays > 0) {
    $files = array_merge(glob($dailyDir . '/*.sql.gz') ?: [], glob($dailyDir . '/*.zip') ?: []);
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    foreach (array_slice($files, $retentionDays) as $old) {
        @unlink($old);
        @error_log("[Backup] Removed old (retention={$retentionDays}d): " . $old);
    }
} else {
    @error_log("[Backup] Retention disabled (BACKUP_RETENTION_DAYS=0), keeping all backups");
}

// Keep weekly/monthly retention at fixed values (4 weekly, 12 monthly)
foreach ([$weeklyDir => 4, $monthlyDir => 12] as $dir => $keep) {
    $files = array_merge(glob($dir . '/*.sql.gz') ?: [], glob($dir . '/*.zip') ?: []);
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
        @error_log("[Backup] Removed old archive: " . $old);
    }
}

echo "Backup complete: " . $filepath . " (" . $size . "KB)\n";
cron_state_mark_success($pdo, $jobName, "Created {$filepath} ({$size}KB)");
