SET @tax_zip_complexity_id_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'tax_zip_complexity'
      AND column_name = 'id'
);

SET @tax_zip_complexity_primary_zip := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'tax_zip_complexity'
      AND index_name = 'PRIMARY'
      AND column_name = 'zip5'
);

SET @tax_zip_complexity_drop_pk_sql := IF(
    @tax_zip_complexity_id_exists = 0 AND @tax_zip_complexity_primary_zip > 0,
    'ALTER TABLE tax_zip_complexity DROP PRIMARY KEY',
    'SELECT 1'
);

PREPARE tax_zip_complexity_drop_pk_stmt FROM @tax_zip_complexity_drop_pk_sql;
EXECUTE tax_zip_complexity_drop_pk_stmt;
DEALLOCATE PREPARE tax_zip_complexity_drop_pk_stmt;

SET @tax_zip_complexity_add_id_sql := IF(
    @tax_zip_complexity_id_exists = 0,
    'ALTER TABLE tax_zip_complexity ADD COLUMN id INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST',
    'SELECT 1'
);

PREPARE tax_zip_complexity_add_id_stmt FROM @tax_zip_complexity_add_id_sql;
EXECUTE tax_zip_complexity_add_id_stmt;
DEALLOCATE PREPARE tax_zip_complexity_add_id_stmt;

SET @tax_zip_complexity_state_zip_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'tax_zip_complexity'
      AND index_name = 'uq_tax_zip_complexity_state_zip'
);

SET @tax_zip_complexity_state_zip_sql := IF(
    @tax_zip_complexity_state_zip_exists = 0,
    'ALTER TABLE tax_zip_complexity ADD UNIQUE KEY uq_tax_zip_complexity_state_zip (state_fips, zip5)',
    'SELECT 1'
);

PREPARE tax_zip_complexity_state_zip_stmt FROM @tax_zip_complexity_state_zip_sql;
EXECUTE tax_zip_complexity_state_zip_stmt;
DEALLOCATE PREPARE tax_zip_complexity_state_zip_stmt;

SET @tax_zip_complexity_zip_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'tax_zip_complexity'
      AND index_name = 'idx_tax_zip_complexity_zip5'
);

SET @tax_zip_complexity_zip_idx_sql := IF(
    @tax_zip_complexity_zip_idx_exists = 0,
    'ALTER TABLE tax_zip_complexity ADD INDEX idx_tax_zip_complexity_zip5 (zip5)',
    'SELECT 1'
);

PREPARE tax_zip_complexity_zip_idx_stmt FROM @tax_zip_complexity_zip_idx_sql;
EXECUTE tax_zip_complexity_zip_idx_stmt;
DEALLOCATE PREPARE tax_zip_complexity_zip_idx_stmt;
