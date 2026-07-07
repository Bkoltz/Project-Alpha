-- Repair cron status tracking for older installs.
-- Cron jobs and settings pages use this table to display last-run state.

CREATE TABLE IF NOT EXISTS cron_job_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL,
    last_run DATETIME NULL,
    status ENUM('running','completed','failed','success') NOT NULL DEFAULT 'running',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    result TEXT NULL,
    error_message TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cron_job_name (job_name),
    INDEX idx_job_name (job_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @cron_last_run_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cron_job_runs' AND column_name = 'last_run'
);
SET @sql := IF(@cron_last_run_exists = 0, 'ALTER TABLE cron_job_runs ADD COLUMN last_run DATETIME NULL AFTER job_name', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE cron_job_runs MODIFY COLUMN status ENUM('running','completed','failed','success') NOT NULL DEFAULT 'running';

SET @cron_started_at_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cron_job_runs' AND column_name = 'started_at'
);
SET @sql := IF(@cron_started_at_exists = 0, 'ALTER TABLE cron_job_runs ADD COLUMN started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cron_completed_at_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cron_job_runs' AND column_name = 'completed_at'
);
SET @sql := IF(@cron_completed_at_exists = 0, 'ALTER TABLE cron_job_runs ADD COLUMN completed_at TIMESTAMP NULL AFTER started_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cron_result_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cron_job_runs' AND column_name = 'result'
);
SET @sql := IF(@cron_result_exists = 0, 'ALTER TABLE cron_job_runs ADD COLUMN result TEXT NULL AFTER completed_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cron_error_message_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cron_job_runs' AND column_name = 'error_message'
);
SET @sql := IF(@cron_error_message_exists = 0, 'ALTER TABLE cron_job_runs ADD COLUMN error_message TEXT NULL AFTER result', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cron_updated_at_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cron_job_runs' AND column_name = 'updated_at'
);
SET @sql := IF(@cron_updated_at_exists = 0, 'ALTER TABLE cron_job_runs ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER error_message', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cron_unique_job_name_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'cron_job_runs'
      AND column_name = 'job_name'
      AND non_unique = 0
);
SET @sql := IF(@cron_unique_job_name_exists = 0, 'ALTER TABLE cron_job_runs ADD UNIQUE KEY uq_cron_job_name (job_name)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO cron_job_runs (job_name, last_run, status) VALUES
    ('rotate_logs', NULL, 'success'),
    ('generate_recurring_invoices', NULL, 'success'),
    ('backup_database', NULL, 'success'),
    ('auto_terminate_contracts', NULL, 'success'),
    ('link_expiration_checker', NULL, 'success'),
    ('process_audit_schedules', NULL, 'success'),
    ('send_invoice_reminders', NULL, 'success'),
    ('stripe_reconciliation', NULL, 'success'),
    ('sync_merchant_rate', NULL, 'success');

INSERT IGNORE INTO app_config (organization_id, config_key, config_value) VALUES
    (0, 'backup_hour', '2'),
    (0, 'backup_retention_days', '10'),
    (0, 'backup_mode', 'database');
