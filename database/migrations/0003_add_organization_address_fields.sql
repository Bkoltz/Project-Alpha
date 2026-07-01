SET @org_address_line1_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'organizations'
      AND column_name = 'address_line1'
);

SET @sql := IF(
    @org_address_line1_exists = 0,
    'ALTER TABLE organizations ADD COLUMN address_line1 VARCHAR(255) NULL AFTER notes',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @org_address_line2_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'organizations'
      AND column_name = 'address_line2'
);

SET @sql := IF(
    @org_address_line2_exists = 0,
    'ALTER TABLE organizations ADD COLUMN address_line2 VARCHAR(255) NULL AFTER address_line1',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @org_city_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'organizations'
      AND column_name = 'city'
);

SET @sql := IF(
    @org_city_exists = 0,
    'ALTER TABLE organizations ADD COLUMN city VARCHAR(100) NULL AFTER address_line2',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @org_state_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'organizations'
      AND column_name = 'state'
);

SET @sql := IF(
    @org_state_exists = 0,
    'ALTER TABLE organizations ADD COLUMN state VARCHAR(100) NULL AFTER city',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @org_postal_code_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'organizations'
      AND column_name = 'postal_code'
);

SET @sql := IF(
    @org_postal_code_exists = 0,
    'ALTER TABLE organizations ADD COLUMN postal_code VARCHAR(32) NULL AFTER state',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @org_country_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'organizations'
      AND column_name = 'country'
);

SET @sql := IF(
    @org_country_exists = 0,
    'ALTER TABLE organizations ADD COLUMN country VARCHAR(100) NULL AFTER postal_code',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
