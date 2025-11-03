-- Add deposit fields to quotes table
USE project_alpha;

-- Add deposit_type column to quotes if it doesn't exist
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'quotes' 
                AND COLUMN_NAME = 'deposit_type');
SET @sql := IF(@exists = 0, 
    "ALTER TABLE quotes ADD COLUMN deposit_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none' AFTER total",
    'SELECT "deposit_type already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add deposit_amount column to quotes if it doesn't exist
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'quotes' 
                AND COLUMN_NAME = 'deposit_amount');
SET @sql := IF(@exists = 0, 
    'ALTER TABLE quotes ADD COLUMN deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER deposit_type',
    'SELECT "deposit_amount already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
