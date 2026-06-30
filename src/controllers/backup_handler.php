<?php
// src/controllers/backup_handler.php
// Handle backup and restore operations from Settings page

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/backup_archive.php';

$action = $_POST['action'] ?? '';
$backupsDir = '/var/www/backups';
$settingsUrl = '/?page=settings&tab=backup';

switch ($action) {
    case 'backup_now':
        // Run the backup script
        $output = [];
        $returnCode = 0;
        $backupScript = __DIR__ . '/../cron/backup_database.php';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($backupScript) . ' 2>&1', $output, $returnCode);
        if ($returnCode === 0) {
            $_SESSION['flash_backup'] = [
                'type' => 'success',
                'message' => 'Backup completed successfully.'
            ];
        } else {
            $errorOutput = implode("\n", $output);
            $_SESSION['flash_backup'] = [
                'type' => 'error',
                'message' => 'Backup failed: ' . ($errorOutput ?: 'Unknown error (exit code ' . $returnCode . ')')
            ];
        }
        header('Location: ' . $settingsUrl);
        exit;

    case 'update_settings':
        $retentionDays = (int)($_POST['retention_days'] ?? 10);
        $backupHour = (int)($_POST['backup_hour'] ?? 2);
        $backupMode = ($_POST['backup_mode'] ?? 'database') === 'full' ? 'full' : 'database';

        if ($retentionDays < 0 || $retentionDays > 365) {
            $_SESSION['flash_backup'] = [
                'type' => 'error',
                'message' => 'Retention days must be between 0 and 365.'
            ];
            header('Location: ' . $settingsUrl);
            exit;
        }
        if ($backupHour < 0 || $backupHour > 23) {
            $_SESSION['flash_backup'] = [
                'type' => 'error',
                'message' => 'Backup hour must be between 0 and 23.'
            ];
            header('Location: ' . $settingsUrl);
            exit;
        }

        // Save to app_config
        $setting = $pdo->prepare('INSERT INTO app_config (organization_id,config_key,config_value) VALUES (0,?,?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)');
        $setting->execute(['backup_retention_days', (string)$retentionDays]);
        $setting->execute(['backup_hour', (string)$backupHour]);
        $setting->execute(['backup_mode', $backupMode]);

        $_SESSION['flash_backup'] = [
            'type' => 'success',
                'message' => "Backup settings saved. Mode: {$backupMode}; retention: {$retentionDays} backups; schedule check: " . str_pad($backupHour, 2, '0', STR_PAD_LEFT) . ":30 UTC."
        ];
        header('Location: ' . $settingsUrl);
        exit;

    case 'restore':
        $backupFile = $_POST['backup_file'] ?? '';
        $uploadFile = $_FILES['backup_upload'] ?? [];
        $confirmed = ($_POST['confirm_restore'] ?? '') === 'yes';

        if (!$confirmed) {
            $_SESSION['flash_backup'] = [
                'type' => 'error',
                'message' => 'You must confirm the restore operation.'
            ];
            header('Location: ' . $settingsUrl);
            exit;
        }

        $sourceFile = '';
        $backupRoot = realpath($backupsDir);
        $relative = str_replace('\\', '/', (string)$backupFile);
        if ($backupRoot && $relative !== '' && !str_contains($relative, '..') && preg_match('#^(daily|weekly|monthly)/[A-Za-z0-9._-]+(?:\.sql\.gz|\.(?:db|full)\.zip)$#', $relative)) {
            $candidate = realpath($backupRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
            if ($candidate && is_file($candidate) && str_starts_with($candidate, $backupRoot . DIRECTORY_SEPARATOR)) {
                $sourceFile = $candidate;
            }
        } elseif (!empty($uploadFile['tmp_name']) && is_uploaded_file($uploadFile['tmp_name'])) {
            $name = strtolower((string)($uploadFile['name'] ?? ''));
            if (preg_match('/(?:\.sql(?:\.gz)?|\.(?:db|full)\.zip)$/', $name) && (int)($uploadFile['size'] ?? 0) <= 500 * 1024 * 1024) {
                $sourceFile = $uploadFile['tmp_name'];
            }
        }

        if (empty($sourceFile)) {
            $_SESSION['flash_backup'] = [
                'type' => 'error',
                'message' => 'No backup file selected or uploaded.'
            ];
            header('Location: ' . $settingsUrl);
            exit;
        }

        // Create emergency backup before restore
        $backupOutput = [];
        $backupCode = 0;
        $backupScript = __DIR__ . '/../cron/backup_database.php';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($backupScript) . ' 2>&1', $backupOutput, $backupCode);
        if ($backupCode !== 0) {
            $_SESSION['flash_backup'] = ['type' => 'error', 'message' => 'Restore stopped because the pre-restore backup failed.'];
            header('Location: ' . $settingsUrl);
            exit;
        }

        $restoreWorkspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pa_restore_' . bin2hex(random_bytes(8));
        if (!mkdir($restoreWorkspace, 0700, true)) {
            $_SESSION['flash_backup'] = ['type' => 'error', 'message' => 'Could not create a secure restore workspace.'];
            header('Location: ' . $settingsUrl);
            exit;
        }
        try {
            $restoreSource = backup_open_restore_source($sourceFile, $restoreWorkspace, backup_archive_key());
            $databaseSource = $restoreSource['database_file'];
        } catch (Throwable $e) {
            @rmdir($restoreWorkspace);
            $_SESSION['flash_backup'] = ['type' => 'error', 'message' => $e->getMessage()];
            header('Location: ' . $settingsUrl);
            exit;
        }
        
        // Apply restore
        $db = getenv('MYSQL_DATABASE') ?: 'project_alpha';
        $user = getenv('MYSQL_USER') ?: 'root';
        $pass = getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: '';
        $host = getenv('DB_HOST') ?: 'db';

        $cmd = '';
        if (pathinfo($databaseSource, PATHINFO_EXTENSION) === 'gz') {
            $cmd = sprintf(
                'gunzip -c %s | mysql -h%s -u%s -p%s %s 2>&1',
                escapeshellarg($databaseSource),
                escapeshellarg($host),
                escapeshellarg($user),
                escapeshellarg($pass),
                escapeshellarg($db)
            );
        } else {
            $cmd = sprintf(
                'mysql -h%s -u%s -p%s %s < %s 2>&1',
                escapeshellarg($host),
                escapeshellarg($user),
                escapeshellarg($pass),
                escapeshellarg($db),
                escapeshellarg($databaseSource)
            );
        }

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode === 0 && !empty($restoreSource['full']) && $restoreSource['zip'] instanceof ZipArchive) {
            try {
                backup_restore_full_files($restoreSource['zip']);
            } catch (Throwable $e) {
                $returnCode = 1;
                $output[] = 'Database restored, but application files failed: ' . $e->getMessage();
            }
        }
        if ($restoreSource['zip'] instanceof ZipArchive) {
            $restoreSource['zip']->close();
        }
        if ($databaseSource !== $sourceFile) {
            @unlink($databaseSource);
        }
        @rmdir($restoreWorkspace);

        if ($returnCode === 0) {
            $_SESSION['flash_backup'] = [
                'type' => 'success',
                'message' => !empty($restoreSource['full'])
                    ? 'Database, uploads, and configuration restored successfully. An emergency backup was created first.'
                    : 'Database restored successfully. An emergency backup was created before restore.'
            ];
        } else {
            $_SESSION['flash_backup'] = [
                'type' => 'error',
                'message' => 'Restore failed. Error: ' . implode(' ', $output)
            ];
        }
        header('Location: ' . $settingsUrl);
        exit;
}
