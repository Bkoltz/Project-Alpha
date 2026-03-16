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
            job_name VARCHAR(100) NOT NULL UNIQUE,
            last_run DATETIME NULL,
            status ENUM("success","failed") NOT NULL DEFAULT "success",
            error_message TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_job_name (job_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
    echo "Table 'cron_job_runs' created successfully.\n";
    
    // Initialize job entries
    $jobs = [
        'generate_recurring_invoices',
        'send_invoice_reminders',
        'auto_terminate_contracts',
        'link_expiration_checker',
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
