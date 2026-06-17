<?php
// src/views/pages/settings/backup.php
// Database backup settings page

if (!defined('BASE_DIR')) die('Direct access not allowed.');

// Get existing backups
$backupsDir = '/var/www/backups';
$dailyDir = $backupsDir . '/daily';
$weeklyDir = $backupsDir . '/weekly';
$monthlyDir = $backupsDir . '/monthly';

$backups = [];
foreach ([$dailyDir, $weeklyDir, $monthlyDir] as $dir) {
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.sql.gz') as $file) {
            $backups[] = [
                'file' => basename($file),
                'path' => $file,
                'dir' => basename(dirname($file)),
                'size' => round(filesize($file) / 1024, 1),
                'created' => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }
    }
}

// Sort by newest first
usort($backups, function($a, $b) {
    return strtotime($b['created']) - strtotime($a['created']);
});

// Check last backup
$lastBackup = isset($backups[0]) ? $backups[0]['created'] : 'Never';
$backupCount = count($backups);

// Handle flash messages
$flash = isset($_SESSION['flash_backup']) ? $_SESSION['flash_backup'] : null;
unset($_SESSION['flash_backup']);

?>

<div class="settings-section" id="settings-backup">
    <h3>Database Backups</h3>
    
    <?php if ($flash): ?>
    <div class="flash-message flash-<?php echo htmlspecialchars($flash['type']); ?">
        <?php echo htmlspecialchars($flash['message']); ?>
    </div>
    <?php endif; ?>

    <div class="backup-status-card">
        <div class="status-item">
            <span class="status-label">Last Backup:</span>
            <span class="status-value"><?php echo htmlspecialchars($lastBackup); ?></span>
        </div>
        <div class="status-item">
            <span class="status-label">Total Backups:</span>
            <span class="status-value"><?php echo $backupCount; ?></span>
        </div>
        <div class="status-item">
            <span class="status-label">Retention:</span>
            <span class="status-value">7 daily, 4 weekly, 12 monthly</span>
        </div>
        <div class="status-item">
            <span class="status-label">Schedule:</span>
            <span class="status-value">Daily at 2:30 AM UTC</span>
        </div>
    </div>

    <form method="POST" action="/?page=settings-backup" class="backup-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="backup_now">
        <button type="submit" class="btn btn-primary">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Backup Now
        </button>
        <span class="help-text">Creates a new database backup immediately.</span>
    </form>

    <hr>

    <h4>Restore from Backup</h4>
    <form method="POST" action="/?page=settings-backup" class="backup-form" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="restore">
        
        <div class="form-group">
            <label>Select Backup File</label>
            <select name="backup_file" class="form-control">
                <option value="">-- Select a backup --</option>
                <?php foreach ($backups as $b): ?>
                <option value="<?php echo htmlspecialchars($b['path']); ?">
                    <?php echo htmlspecialchars($b['dir'] . '/' . $b['file']); ?> (<?php echo $b['size']; ?>KB, <?php echo htmlspecialchars($b['created']); ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Or upload a backup file</label>
            <input type="file" name="backup_upload" accept=".sql,.sql.gz" class="form-control">
        </div>
        
        <div class="form-group">
            <label>
                <input type="checkbox" name="confirm_restore" value="yes" required>
                I understand this will overwrite the current database
            </label>
        </div>
        
        <button type="submit" class="btn btn-danger">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>
                <path d="M21 3v5h-5"/>
                <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/>
                <path d="M3 21v-5h5"/>
            </svg>
            Restore Database
        </button>
        <span class="warning-text">Warning: This will overwrite all current data. Make a backup first!</span>
    </form>

    <hr>

    <h4>Existing Backups</h4>
    <?php if (empty($backups)): ?>
        <p class="text-muted">No backups found. Run "Backup Now" or wait for the daily scheduled backup.</p>
    <?php else: ?>
        <table class="table backup-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Filename</th>
                    <th>Size</th>
                    <th>Created</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $b): ?>
                <tr>
                    <td><span class="badge badge-<?php echo htmlspecialchars($b['dir']); ?"><?php echo ucfirst($b['dir']); ?></span></td>
                    <td><?php echo htmlspecialchars($b['file']); ?></td>
                    <td><?php echo $b['size']; ?> KB</td>
                    <td><?php echo htmlspecialchars($b['created']); ?></td>
                    <td>
                        <a href="/?page=settings-backup&amp;download=<?php echo urlencode($b['path']); ?" class="btn btn-sm btn-secondary">Download</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<style>
.backup-status-card {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}
.status-item { display: flex; flex-direction: column; }
.status-label { font-size: 0.85rem; color: #6c757d; margin-bottom: 0.25rem; }
.status-value { font-weight: 600; font-size: 1rem; }
.backup-form { margin: 1rem 0; }
.backup-form .btn { display: inline-flex; align-items: center; gap: 0.5rem; margin-right: 1rem; }
.backup-form .icon { width: 18px; height: 18px; }
.help-text { color: #6c757d; font-size: 0.9rem; }
.warning-text { color: #dc3545; font-size: 0.9rem; font-weight: 500; }
.form-group { margin-bottom: 1rem; }
.form-control { width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; margin-top: 0.25rem; }
.btn-danger { background: #dc3545; color: white; }
.btn-danger:hover { background: #c82333; }
.backup-table { width: 100%; border-collapse: collapse; }
.backup-table th, .backup-table td { padding: 0.75rem; text-align: left; border-bottom: 1px solid #e9ecef; }
.backup-table th { font-weight: 600; background: #f8f9fa; }
.badge { padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: 500; }
.badge-daily { background: #d4edda; color: #155724; }
.badge-weekly { background: #fff3cd; color: #856404; }
.badge-monthly { background: #cce5ff; color: #004085; }
.btn-sm { padding: 0.25rem 0.5rem; font-size: 0.85rem; }
.btn-secondary { background: #6c757d; color: white; text-decoration: none; border-radius: 4px; }
.flash-success { background: #d4edda; color: #155724; padding: 0.75rem; border-radius: 4px; margin-bottom: 1rem; }
.flash-error { background: #f8d7da; color: #721c24; padding: 0.75rem; border-radius: 4px; margin-bottom: 1rem; }
.text-muted { color: #6c757d; }
</style>
