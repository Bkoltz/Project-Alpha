-- Update deposit fields and add fulfillment date
USE project_alpha;

-- Add deposit_type column if it doesn't exist
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'contracts' 
                AND COLUMN_NAME = 'deposit_type');
SET @sql := IF(@exists = 0, 
    "ALTER TABLE contracts ADD COLUMN deposit_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none' AFTER total",
    'SELECT "deposit_type already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Modify deposit_amount to be after deposit_type
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'contracts' 
                AND COLUMN_NAME = 'deposit_amount');
SET @sql := IF(@exists = 1, 
    'ALTER TABLE contracts MODIFY COLUMN deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER deposit_type',
    'ALTER TABLE contracts ADD COLUMN deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER deposit_type');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add fulfillment_date to contracts if it doesn't exist
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'contracts' 
                AND COLUMN_NAME = 'fulfillment_date');
SET @sql := IF(@exists = 0, 
    'ALTER TABLE contracts ADD COLUMN fulfillment_date DATE NULL AFTER estimated_completion',
    'SELECT "fulfillment_date already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add fulfillment_date to quotes if it doesn't exist
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'quotes' 
                AND COLUMN_NAME = 'fulfillment_date');
SET @sql := IF(@exists = 0, 
    'ALTER TABLE quotes ADD COLUMN fulfillment_date DATE NULL AFTER total',
    'SELECT "fulfillment_date already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add fulfillment_date to invoices if it doesn't exist
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'invoices' 
                AND COLUMN_NAME = 'fulfillment_date');
SET @sql := IF(@exists = 0, 
    'ALTER TABLE invoices ADD COLUMN fulfillment_date DATE NULL AFTER estimated_completion',
    'SELECT "fulfillment_date already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
