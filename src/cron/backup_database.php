<?php
/**
 * Pure PHP database backup script (avoids SSL issues with mysqldump)
 * Creates timestamped SQL dump to /var/www/backups/
 * Retention: 7 daily, 4 weekly, 12 monthly
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/cron_state.php';

$jobName = 'backup_database';

$backupDir = '/var/www/backups';
$dailyDir = $backupDir . '/daily';
$weeklyDir = $backupDir . '/weekly';
$monthlyDir = $backupDir . '/monthly';

foreach ([$dailyDir, $weeklyDir, $monthlyDir] as $d) {
    if (!is_dir($d)) mkdir($d, 0750, true);
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
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 0) {
        gzwrite($gz, "INSERT INTO `$table` VALUES\n");
        $first = true;
        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } elseif (is_numeric($value)) {
                    $values[] = $value;
                } else {
                    $values[] = "'" . str_replace("'", "\\'", $value) . "'";
                }
            }
            if (!$first) gzwrite($gz, ",\n");
            gzwrite($gz, '(' . implode(', ', $values) . ')');
            $first = false;
        }
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

// Retention
$retention = [$dailyDir => 7, $weeklyDir => 4, $monthlyDir => 12];
foreach ($retention as $dir => $keep) {
    $files = glob($dir . '/*.sql.gz');
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
        @error_log("[Backup] Removed old: " . $old);
    }
}

echo "Backup complete: " . $filepath . " (" . $size . "KB)\n";
cron_state_mark_success($pdo, $jobName, "Created {$filepath} ({$size}KB)");
