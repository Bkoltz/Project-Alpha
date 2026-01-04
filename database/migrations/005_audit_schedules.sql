-- 005_audit_schedules.sql
-- Table for storing audit report schedules

CREATE TABLE IF NOT EXISTS `audit_schedules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `frequency` ENUM('weekly', 'monthly', 'quarterly', 'annually') NOT NULL,
  `date_range_type` ENUM('last_week', 'last_month', 'last_quarter', 'last_year', 'current_year', 'all_time') NOT NULL DEFAULT 'current_year',
  `email_addresses` TEXT NOT NULL COMMENT 'JSON array of email addresses',
  `options` JSON NULL COMMENT 'Additional options: include_contracts, include_quotes, include_pdfs, include_unpaid_invoices',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `next_run_at` DATETIME NULL COMMENT 'Next scheduled execution time',
  `last_run_at` DATETIME NULL COMMENT 'Last successful execution time'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Index for finding due schedules
CREATE INDEX idx_next_run ON audit_schedules(next_run_at, is_active);

-- Add schedule execution log table
CREATE TABLE IF NOT EXISTS `audit_schedule_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `schedule_id` INT NOT NULL,
  `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('success', 'failed') NOT NULL,
  `error_message` TEXT NULL,
  `file_path` VARCHAR(500) NULL COMMENT 'Path to generated audit file',
  `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (`schedule_id`) REFERENCES `audit_schedules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_schedule_logs ON audit_schedule_logs(schedule_id, executed_at);
