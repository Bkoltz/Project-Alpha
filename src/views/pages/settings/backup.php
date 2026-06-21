<?php
// src/views/pages/settings/backup.php
// Database backup settings page

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

// Get existing backups (also check .gpg encrypted backups)
$backupsDir = '/var/www/backups';

// Read retention from env (matches backup_database.php)
$retentionDays = (int)(getenv('BACKUP_RETENTION_DAYS') ?: '10');
$retentionLabel = $retentionDays > 0 ? "{$retentionDays} daily backups" : 'Unlimited (retention disabled)';

// Read backup hour from app_config (default 2 = 2:00 AM UTC)
$backupHour = 2;
try {
    $cfgStmt = $pdo->prepare("SELECT config_value FROM app_config WHERE organization_id = 0 AND config_key = 'backup_hour'");
    $cfgStmt->execute();
    $cfgRow = $cfgStmt->fetch(PDO::FETCH_ASSOC);
    if ($cfgRow) $backupHour = (int)$cfgRow['config_value'];
} catch (Exception $e) {}

// Also check if retention is overridden in app_config
try {
    $cfgStmt2 = $pdo->prepare("SELECT config_value FROM app_config WHERE organization_id = 0 AND config_key = 'backup_retention_days'");
    $cfgStmt2->execute();
    $cfgRow2 = $cfgStmt2->fetch(PDO::FETCH_ASSOC);
    if ($cfgRow2) $retentionDays = (int)$cfgRow2['config_value'];
} catch (Exception $e) {}

$backups = [];
if (is_dir($backupsDir)) {
    // Check daily/ subdirectory first (where the backup script writes)
    $searchDirs = [
        $backupsDir . '/daily',
        $backupsDir . '/weekly',
        $backupsDir . '/monthly',
        $backupsDir, // root level (legacy backups)
    ];
    foreach ($searchDirs as $searchDir) {
        if (!is_dir($searchDir)) continue;
        foreach (glob($searchDir . '/*.sql.gz*') as $file) {
            if (is_file($file)) {
                $backups[] = [
                    'file' => basename($file),
                    'path' => $file,
                    'size' => round(filesize($file) / 1024, 1),
                    'created' => date('Y-m-d H:i:s', filemtime($file)),
                    'encrypted' => substr($file, -4) === '.gpg',
                ];
            }
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
    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
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
            <span class="status-value"><?php echo htmlspecialchars($retentionLabel); ?></span>
        </div>
        <div class="status-item">
            <span class="status-label">Schedule:</span>
            <span class="status-value">Daily at 2:30 AM UTC</span>
        </div>
    </div>

    <hr>

    <h4>Backup Configuration</h4>
    <form method="POST" action="/?page=settings-backup" class="backup-form">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="action" value="update_settings">

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; padding:1rem; background:#f8f9fa; border-radius:8px; margin-bottom:1rem;">
            <div class="form-group" style="margin:0;">
                <label for="retention_days" style="font-size:0.85rem; color:#6c757d; margin-bottom:0.35rem; display:block;">Retention (days)</label>
                <input type="number" name="retention_days" id="retention_days"
                       value="<?php echo htmlspecialchars($retentionDays); ?>"
                       min="0" max="365" class="input" style="width:100%; padding:0.5rem; border:1px solid #ddd; border-radius:6px; font-size:0.95rem;">
                <span class="help-text" style="display:block; margin-top:0.35rem; font-size:0.8rem;">Keep this many daily backups. 0 = keep all (no auto-cleanup).</span>
            </div>

            <div class="form-group" style="margin:0;">
                <label for="backup_hour" style="font-size:0.85rem; color:#6c757d; margin-bottom:0.35rem; display:block;">Backup Time (UTC)</label>
                <select name="backup_hour" id="backup_hour" class="input" style="width:100%; padding:0.5rem; border:1px solid #ddd; border-radius:6px; font-size:0.95rem;">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                    <option value="<?php echo $h; ?>" <?php echo ($h == $backupHour) ? 'selected' : ''; ?>>
                        <?php echo str_pad($h, 2, '0', STR_PAD_LEFT); ?>:00 UTC
                    </option>
                    <?php endfor; ?>
                </select>
                <span class="help-text" style="display:block; margin-top:0.35rem; font-size:0.8rem;">Daily backup runs at this hour. Restart cron container to apply.</span>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Save Settings</button>
    </form>

    <form method="POST" action="/?page=settings-backup" class="backup-form">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
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
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="action" value="restore">
        
        <div class="form-group">
            <label>Select Backup File</label>
            <select name="backup_file" class="input">
                <option value="">-- Select a backup --</option>
                <?php foreach ($backups as $b): ?>
                <option value="<?php echo htmlspecialchars($b['path']); ?>">
                    <?php echo htmlspecialchars($b['file']); ?> (<?php echo $b['size']; ?>KB, <?php echo htmlspecialchars($b['created']); ?><?php echo $b['encrypted'] ? ', encrypted' : ''; ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Or upload a backup file</label>
            <input type="file" name="backup_upload" accept=".sql,.sql.gz,.sql.gz.gpg" class="input">
        </div>
        
        <div class="form-group">
            <label>
                <input type="checkbox" name="confirm_restore" value="yes" required>
                I understand this will overwrite the current database
            </label>
        </div>
        
        <button type="submit" class="btn btn-danger">
            Restore Database
        </button>
        <span class="warning-text">Warning: This will overwrite all current data. Make a backup first!</span>
    </form>

    <hr>

    <h4>Existing Backups</h4>
    <?php if (empty($backups)): ?>
        <p class="muted">No backups found. Run "Backup Now" or wait for the daily scheduled backup.</p>
    <?php else: ?>
        <div class="pa-table-wrap">
            <table class="pa-table">
                <thead>
                    <tr>
                        <th>Filename</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th>Encrypted</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $b): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($b['file']); ?></td>
                        <td><?php echo $b['size']; ?> KB</td>
                        <td><?php echo htmlspecialchars($b['created']); ?></td>
                        <td><?php echo $b['encrypted'] ? 'Yes' : 'No'; ?></td>
                        <td>
                            <a href="/?page=settings/backup&download=<?php echo urlencode($b['path']); ?>" class="btn btn-sm">Download</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
