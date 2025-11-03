-- Add check_number and notes to payments table
USE project_alpha;

-- Add check_number column if it doesn't exist
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'payments' 
                AND COLUMN_NAME = 'check_number');
SET @sql := IF(@exists = 0, 
    'ALTER TABLE payments ADD COLUMN check_number VARCHAR(100) NULL AFTER method',
    'SELECT "check_number already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add notes column if it doesn't exist
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'payments' 
                AND COLUMN_NAME = 'notes');
SET @sql := IF(@exists = 0, 
    'ALTER TABLE payments ADD COLUMN notes TEXT NULL AFTER check_number',
    'SELECT "notes already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
