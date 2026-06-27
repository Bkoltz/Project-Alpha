-- Migration 032: Project invoice billing cadence
-- Lets ongoing projects hold invoice due dates until the end of a billing period.

USE project_alpha;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'invoice_billing_period'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE projects ADD COLUMN invoice_billing_period ENUM(''per_invoice'',''monthly'') NOT NULL DEFAULT ''monthly'' AFTER status',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'invoice_net_terms_days'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE projects ADD COLUMN invoice_net_terms_days INT NULL AFTER invoice_billing_period',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
