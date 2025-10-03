-- database/migrations/004_project_tables.sql
-- Tables to support project codes and notes, and missing columns on contracts

CREATE TABLE IF NOT EXISTS project_counters (
  prefix VARCHAR(32) PRIMARY KEY,
  next_seq INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_meta (
  project_code VARCHAR(64) PRIMARY KEY,
  client_id INT NOT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_project_meta_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add contracts.project_code if missing
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contracts' AND COLUMN_NAME='project_code');
SET @sql := IF(@col_exists=0, 'ALTER TABLE contracts ADD COLUMN project_code VARCHAR(64) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add contracts.doc_number if missing
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contracts' AND COLUMN_NAME='doc_number');
SET @sql := IF(@col_exists=0, 'ALTER TABLE contracts ADD COLUMN doc_number INT NULL', 'SELECT 1');
PREPARE stmt2 FROM @sql; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

-- Add index idx_contracts_doc_number if missing
SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contracts' AND INDEX_NAME='idx_contracts_doc_number');
SET @sql := IF(@idx_exists=0, 'ALTER TABLE contracts ADD INDEX idx_contracts_doc_number (doc_number)', 'SELECT 1');
PREPARE stmt3 FROM @sql; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;
