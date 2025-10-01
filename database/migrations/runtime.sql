-- database/migrations/runtime.sql
-- Idempotent runtime migrations to ensure schema matches application needs.
-- These run at container startup (web) and are safe to re-run.

-- Use database (the web start script supplies -D, but this is harmless if present)
USE project_alpha;

-- Clients: add address fields if missing (works on MySQL without IF NOT EXISTS)
SET @db := DATABASE();
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='clients' AND COLUMN_NAME='address_line1');
SET @sql := IF(@exists=0, 'ALTER TABLE clients ADD COLUMN address_line1 VARCHAR(200) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='clients' AND COLUMN_NAME='address_line2');
SET @sql := IF(@exists=0, 'ALTER TABLE clients ADD COLUMN address_line2 VARCHAR(200) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='clients' AND COLUMN_NAME='city');
SET @sql := IF(@exists=0, 'ALTER TABLE clients ADD COLUMN city VARCHAR(100) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='clients' AND COLUMN_NAME='state');
SET @sql := IF(@exists=0, 'ALTER TABLE clients ADD COLUMN state VARCHAR(100) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='clients' AND COLUMN_NAME='postal');
SET @sql := IF(@exists=0, 'ALTER TABLE clients ADD COLUMN postal VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='clients' AND COLUMN_NAME='country');
SET @sql := IF(@exists=0, 'ALTER TABLE clients ADD COLUMN country VARCHAR(100) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Contracts: add discount/tax/totals if missing
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contracts' AND COLUMN_NAME='discount_type');
SET @sql := IF(@exists=0, "ALTER TABLE contracts ADD COLUMN discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none'", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contracts' AND COLUMN_NAME='discount_value');
SET @sql := IF(@exists=0, 'ALTER TABLE contracts ADD COLUMN discount_value DECIMAL(10,2) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contracts' AND COLUMN_NAME='tax_percent');
SET @sql := IF(@exists=0, 'ALTER TABLE contracts ADD COLUMN tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contracts' AND COLUMN_NAME='subtotal');
SET @sql := IF(@exists=0, 'ALTER TABLE contracts ADD COLUMN subtotal DECIMAL(12,2) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contracts' AND COLUMN_NAME='total');
SET @sql := IF(@exists=0, 'ALTER TABLE contracts ADD COLUMN total DECIMAL(12,2) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
