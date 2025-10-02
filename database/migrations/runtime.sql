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

-- Archive tables (idempotent creates)
CREATE TABLE IF NOT EXISTS archived_clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NULL,
  phone VARCHAR(50) NULL,
  organization VARCHAR(150) NULL,
  notes TEXT NULL,
  address_line1 VARCHAR(200) NULL,
  address_line2 VARCHAR(200) NULL,
  city VARCHAR(100) NULL,
  state VARCHAR(100) NULL,
  postal VARCHAR(20) NULL,
  country VARCHAR(100) NULL,
  created_at TIMESTAMP NULL,
  archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS archived_entities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  entity_type VARCHAR(32) NOT NULL,
  entity_id INT NOT NULL,
  payload JSON NOT NULL,
  archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_arch_entities_client (client_id),
  INDEX idx_arch_entities_type (entity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Document cross-linking: project_code on quotes/contracts/invoices
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='quotes' AND COLUMN_NAME='project_code');
SET @sql := IF(@exists=0, 'ALTER TABLE quotes ADD COLUMN project_code VARCHAR(32) NULL, ADD INDEX idx_quotes_project_code (project_code)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contracts' AND COLUMN_NAME='project_code');
SET @sql := IF(@exists=0, 'ALTER TABLE contracts ADD COLUMN project_code VARCHAR(32) NULL, ADD INDEX idx_contracts_project_code (project_code)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='invoices' AND COLUMN_NAME='project_code');
SET @sql := IF(@exists=0, 'ALTER TABLE invoices ADD COLUMN project_code VARCHAR(32) NULL, ADD INDEX idx_invoices_project_code (project_code)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Scheduled date fields for calendar views
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contracts' AND COLUMN_NAME='scheduled_date');
SET @sql := IF(@exists=0, 'ALTER TABLE contracts ADD COLUMN scheduled_date DATE NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='invoices' AND COLUMN_NAME='scheduled_date');
SET @sql := IF(@exists=0, 'ALTER TABLE invoices ADD COLUMN scheduled_date DATE NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Shared document number across quotes/contracts/invoices
ALTER TABLE quotes ADD COLUMN IF NOT EXISTS doc_number INT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS doc_number INT NULL;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS doc_number INT NULL;

-- Contracts: schedule/terms fields (idempotent)
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contracts' AND COLUMN_NAME='terms');
SET @sql := IF(@exists=0, 'ALTER TABLE contracts ADD COLUMN terms TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contracts' AND COLUMN_NAME='estimated_completion');
SET @sql := IF(@exists=0, 'ALTER TABLE contracts ADD COLUMN estimated_completion VARCHAR(200) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contracts' AND COLUMN_NAME='weather_pending');
SET @sql := IF(@exists=0, 'ALTER TABLE contracts ADD COLUMN weather_pending TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Invoices: schedule fields
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='invoices' AND COLUMN_NAME='estimated_completion');
SET @sql := IF(@exists=0, 'ALTER TABLE invoices ADD COLUMN estimated_completion VARCHAR(200) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='invoices' AND COLUMN_NAME='weather_pending');
SET @sql := IF(@exists=0, 'ALTER TABLE invoices ADD COLUMN weather_pending TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Project counters table (for generating project_code sequences)
CREATE TABLE IF NOT EXISTS project_counters (
  prefix VARCHAR(16) NOT NULL PRIMARY KEY,
  next_seq INT NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Project metadata (shared notes per project_code)
CREATE TABLE IF NOT EXISTS project_meta (
  project_code VARCHAR(32) NOT NULL PRIMARY KEY,
  client_id INT NOT NULL,
  notes TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_project_meta_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Shared document number across quotes/contracts/invoices
-- Use IF NOT EXISTS where supported; otherwise fall back to INFORMATION_SCHEMA checks above.
ALTER TABLE quotes ADD COLUMN IF NOT EXISTS doc_number INT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS doc_number INT NULL;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS doc_number INT NULL;

-- Contracts: schedule/terms fields (idempotent)
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contracts' AND COLUMN_NAME='terms');
SET @sql := IF(@exists=0, 'ALTER TABLE contracts ADD COLUMN terms TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contracts' AND COLUMN_NAME='estimated_completion');
SET @sql := IF(@exists=0, 'ALTER TABLE contracts ADD COLUMN estimated_completion VARCHAR(200) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contracts' AND COLUMN_NAME='weather_pending');
SET @sql := IF(@exists=0, 'ALTER TABLE contracts ADD COLUMN weather_pending TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Invoices: schedule fields
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='invoices' AND COLUMN_NAME='estimated_completion');
SET @sql := IF(@exists=0, 'ALTER TABLE invoices ADD COLUMN estimated_completion VARCHAR(200) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='invoices' AND COLUMN_NAME='weather_pending');
SET @sql := IF(@exists=0, 'ALTER TABLE invoices ADD COLUMN weather_pending TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
