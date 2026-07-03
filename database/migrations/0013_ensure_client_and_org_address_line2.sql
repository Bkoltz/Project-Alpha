-- Ensure apartment/suite address support exists on legacy databases.
SET @client_address_line2_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'clients'
      AND column_name = 'address_line2'
);

SET @sql := IF(
    @client_address_line2_exists = 0,
    'ALTER TABLE clients ADD COLUMN address_line2 VARCHAR(255) NULL AFTER address_line1',
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
