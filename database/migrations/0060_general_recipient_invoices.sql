-- General-recipient invoices retain their required internal client for accounting,
-- while explicitly suppressing that recipient from externally shared documents.
SET @general_recipient_mode_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices'
      AND column_name = 'recipient_presentation_mode'
);
SET @sql := IF(@general_recipient_mode_exists = 0,
    'ALTER TABLE invoices
        ADD COLUMN recipient_presentation_mode VARCHAR(32) NOT NULL DEFAULT ''named'' AFTER client_id',
    'SELECT 1');
PREPARE general_recipient_mode_stmt FROM @sql;
EXECUTE general_recipient_mode_stmt;
DEALLOCATE PREPARE general_recipient_mode_stmt;

SET @general_recipient_index_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'invoices'
      AND index_name = 'idx_invoices_recipient_presentation'
);
SET @sql := IF(@general_recipient_index_exists = 0,
    'ALTER TABLE invoices
        ADD INDEX idx_invoices_recipient_presentation (recipient_presentation_mode)',
    'SELECT 1');
PREPARE general_recipient_index_stmt FROM @sql;
EXECUTE general_recipient_index_stmt;
DEALLOCATE PREPARE general_recipient_index_stmt;
