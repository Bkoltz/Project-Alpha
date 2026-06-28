-- Migration 034: Project invoice email preferences
-- Adds project-level auto-email control and per-client project invoice recipients.

USE project_alpha;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'project_invoice_auto_email'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE projects ADD COLUMN project_invoice_auto_email TINYINT(1) NOT NULL DEFAULT 1 AFTER invoice_net_terms_days',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'project_clients' AND column_name = 'send_project_invoices'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE project_clients ADD COLUMN send_project_invoices TINYINT(1) NOT NULL DEFAULT 1 AFTER is_primary_billing',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
