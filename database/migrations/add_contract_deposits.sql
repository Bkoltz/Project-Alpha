-- Add deposit fields to contracts table
USE project_alpha;

-- Add deposit_amount column if it doesn't exist
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'contracts' 
                AND COLUMN_NAME = 'deposit_amount');
SET @sql := IF(@exists = 0, 
    'ALTER TABLE contracts ADD COLUMN deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total',
    'SELECT "deposit_amount already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add deposit_paid column if it doesn't exist
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'contracts' 
                AND COLUMN_NAME = 'deposit_paid');
SET @sql := IF(@exists = 0, 
    'ALTER TABLE contracts ADD COLUMN deposit_paid DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER deposit_amount',
    'SELECT "deposit_paid already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
