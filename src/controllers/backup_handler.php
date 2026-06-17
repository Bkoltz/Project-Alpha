<?php
// src/controllers/backup_handler.php
// Handle backup and restore operations from Settings page

if (!defined('BASE_DIR')) die('Direct access not allowed.');

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return;
}

require_once __DIR__ . '/../config/db.php';

$action = $_POST['action'] ?? '';
$backupsDir = '/var/www/backups';

switch ($action) {
    case 'backup_now':
        // Run the backup script
        $output = [];
        $returnCode = 0;
        exec('php /var/www/src/cron/backup_database.php 2>&1', $output, $returnCode);
        if ($returnCode === 0) {
            $_SESSION['flash_backup'] = [
                'type' => 'success',
                'message' => 'Backup completed successfully.'
            ];
        } else {
            $_SESSION['flash_backup'] = [
                'type' => 'error',
                'message' => 'Backup failed. Check server logs for details.'
            ];
        }
        header('Location: /?page=settings-backup');
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
            header('Location: /?page=settings-backup');
            exit;
        }

        $sourceFile = '';
        if (!empty($backupFile) && file_exists($backupFile)) {
            $sourceFile = $backupFile;
        } elseif (!empty($uploadFile['tmp_name']) && is_uploaded_file($uploadFile['tmp_name'])) {
            $sourceFile = $uploadFile['tmp_name'];
        }

        if (empty($sourceFile)) {
            $_SESSION['flash_backup'] = [
                'type' => 'error',
                'message' => 'No backup file selected or uploaded.'
            ];
            header('Location: /?page=settings-backup');
            exit;
        }

        // Create emergency backup before restore
        $emergencyFile = $backupsDir . '/emergency_pre_restore_' . date('Ymd_His') . '.sql.gz';
        require_once __DIR__ . '/../cron/backup_database.php';
        
        // Apply restore
        $db = getenv('MYSQL_DATABASE') ?: 'project_alpha';
        $user = getenv('MYSQL_USER') ?: 'root';
        $pass = getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: '';
        $host = getenv('DB_HOST') ?: 'db';

        $cmd = '';
        if (pathinfo($sourceFile, PATHINFO_EXTENSION) === 'gz') {
            $cmd = sprintf(
                'gunzip -c %s | mysql -h%s -u%s -p%s %s 2>&1',
                escapeshellarg($sourceFile),
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
                escapeshellarg($sourceFile)
            );
        }

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode === 0) {
            $_SESSION['flash_backup'] = [
                'type' => 'success',
                'message' => 'Database restored successfully. An emergency backup was created before restore.'
            ];
        } else {
            $_SESSION['flash_backup'] = [
                'type' => 'error',
                'message' => 'Restore failed. Error: ' . implode(' ', $output)
            ];
        }
        header('Location: /?page=settings-backup');
        exit;
}
