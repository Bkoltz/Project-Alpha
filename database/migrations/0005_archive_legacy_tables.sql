SET @financial_records_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'financial_records'
);

SET @legacy_financial_records_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'legacy_financial_records'
);

SET @legacy_financial_records_target := IF(
    @legacy_financial_records_exists = 0,
    'legacy_financial_records',
    CONCAT('legacy_financial_records_', DATE_FORMAT(UTC_TIMESTAMP(), '%Y%m%d%H%i%s'))
);

SET @sql := IF(
    @financial_records_exists = 1,
    CONCAT('RENAME TABLE financial_records TO ', @legacy_financial_records_target),
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @migrations_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'migrations'
);

SET @legacy_migrations_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'legacy_migrations'
);

SET @legacy_migrations_target := IF(
    @legacy_migrations_exists = 0,
    'legacy_migrations',
    CONCAT('legacy_migrations_', DATE_FORMAT(UTC_TIMESTAMP(), '%Y%m%d%H%i%s'))
);

SET @sql := IF(
    @migrations_exists = 1,
    CONCAT('RENAME TABLE migrations TO ', @legacy_migrations_target),
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
