-- Add durable metadata for individually voided invoices.
-- The invoice row and amounts remain intact for accounting and audit history.

SET @invoice_voided_at_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'voided_at'
);
SET @sql := IF(@invoice_voided_at_exists = 0, 'ALTER TABLE invoices ADD COLUMN voided_at TIMESTAMP NULL AFTER paid_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @invoice_voided_by_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'voided_by'
);
SET @sql := IF(@invoice_voided_by_exists = 0, 'ALTER TABLE invoices ADD COLUMN voided_by INT NULL AFTER voided_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @invoice_void_reason_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'void_reason'
);
SET @sql := IF(@invoice_void_reason_exists = 0, 'ALTER TABLE invoices ADD COLUMN void_reason VARCHAR(500) NULL AFTER voided_by', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @invoice_void_previous_status_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'void_previous_status'
);
SET @sql := IF(@invoice_void_previous_status_exists = 0, 'ALTER TABLE invoices ADD COLUMN void_previous_status VARCHAR(32) NULL AFTER void_reason', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @invoice_voided_at_index_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND index_name = 'idx_invoices_voided_at'
);
SET @sql := IF(@invoice_voided_at_index_exists = 0, 'ALTER TABLE invoices ADD INDEX idx_invoices_voided_at (voided_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
