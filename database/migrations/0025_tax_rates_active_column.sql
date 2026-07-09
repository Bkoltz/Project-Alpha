SET @tax_rates_is_active_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'tax_rates'
      AND column_name = 'is_active'
);

SET @tax_rates_is_active_sql := IF(
    @tax_rates_is_active_exists = 0,
    'ALTER TABLE tax_rates ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_default',
    'SELECT 1'
);

PREPARE tax_rates_is_active_stmt FROM @tax_rates_is_active_sql;
EXECUTE tax_rates_is_active_stmt;
DEALLOCATE PREPARE tax_rates_is_active_stmt;
