-- Add a billing start mode for long-term contracts without rewriting existing schedules.
SET @contracts_billing_start_mode_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contracts'
      AND column_name = 'billing_start_mode'
);

SET @sql := IF(
    @contracts_billing_start_mode_exists = 0,
    'ALTER TABLE contracts ADD COLUMN billing_start_mode ENUM(''on_upload'',''manual'') NULL DEFAULT ''on_upload'' AFTER next_invoice_date',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
