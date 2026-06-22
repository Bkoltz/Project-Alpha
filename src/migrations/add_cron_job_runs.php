<?php
/**
 * Migration: Add cron_job_runs table for catch-up logic
 * 
 * Run in Docker:
 *   docker compose exec app php /var/www/src/migrations/add_cron_job_runs.php
 */
require_once __DIR__ . '/../config/db.php';

try {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS cron_job_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_name VARCHAR(100) NOT NULL,
            last_run DATETIME NULL,
            status ENUM("running","completed","failed","success") NOT NULL DEFAULT "running",
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            result TEXT NULL,
            error_message TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cron_job_name (job_name),
            INDEX idx_job_name (job_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
    echo "Table 'cron_job_runs' created successfully.\n";

    $schemaUpdates = [
        'ALTER TABLE cron_job_runs ADD COLUMN last_run DATETIME NULL AFTER job_name',
        'ALTER TABLE cron_job_runs MODIFY COLUMN status ENUM("running","completed","failed","success") NOT NULL DEFAULT "running"',
        'ALTER TABLE cron_job_runs ADD COLUMN started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status',
        'ALTER TABLE cron_job_runs ADD COLUMN completed_at TIMESTAMP NULL AFTER started_at',
        'ALTER TABLE cron_job_runs ADD COLUMN result TEXT NULL AFTER completed_at',
        'ALTER TABLE cron_job_runs ADD COLUMN error_message TEXT NULL AFTER result',
        'ALTER TABLE cron_job_runs ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER error_message',
        'ALTER TABLE cron_job_runs ADD UNIQUE KEY uq_cron_job_name (job_name)',
    ];
    foreach ($schemaUpdates as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $ignored) {
            // Column/index already exists or an older MySQL variant rejected the no-op.
        }
    }
    
    // Initialize job entries
    $jobs = [
        'generate_recurring_invoices',
        'auto_charge_recurring',
        'backup_database',
        'send_invoice_reminders',
        'auto_terminate_contracts',
        'link_expiration_checker',
        'process_audit_schedules',
        'stripe_reconciliation'
    ];
    
    $stmt = $pdo->prepare('INSERT IGNORE INTO cron_job_runs (job_name, last_run, status) VALUES (?, NULL, "success")');
    foreach ($jobs as $job) {
        $stmt->execute([$job]);
    }
    echo "Job entries initialized.\n";
    
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
