CREATE TABLE IF NOT EXISTS time_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    user_id INT NOT NULL,
    client_id INT NULL,
    project_id INT NULL,
    project_code VARCHAR(64) NULL,
    contract_id INT NULL,
    invoice_id INT NULL,
    service_item_id INT NULL,
    description TEXT NULL,
    started_at DATETIME NULL,
    ended_at DATETIME NULL,
    hours DECIMAL(10,2) NOT NULL DEFAULT 0,
    billable TINYINT(1) DEFAULT 1,
    billed TINYINT(1) DEFAULT 0,
    rate DECIMAL(10,2) DEFAULT 0,
    invoice_item_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_time_entries_user (user_id),
    INDEX idx_time_entries_client (client_id),
    INDEX idx_time_entries_project (project_id),
    INDEX idx_time_entries_project_code (project_code),
    INDEX idx_time_entries_contract (contract_id),
    INDEX idx_time_entries_invoice (invoice_id),
    INDEX idx_time_entries_billable (billable),
    INDEX idx_time_entries_billed (billed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @time_entries_organization_id_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'organization_id'
);
SET @sql := IF(@time_entries_organization_id_exists = 0, 'ALTER TABLE time_entries ADD COLUMN organization_id INT NULL AFTER id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @time_entries_project_code_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'project_code'
);
SET @sql := IF(@time_entries_project_code_exists = 0, 'ALTER TABLE time_entries ADD COLUMN project_code VARCHAR(64) NULL AFTER project_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @time_entries_contract_id_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'contract_id'
);
SET @sql := IF(@time_entries_contract_id_exists = 0, 'ALTER TABLE time_entries ADD COLUMN contract_id INT NULL AFTER project_code', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @time_entries_invoice_id_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'invoice_id'
);
SET @sql := IF(@time_entries_invoice_id_exists = 0, 'ALTER TABLE time_entries ADD COLUMN invoice_id INT NULL AFTER contract_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @time_entries_service_item_id_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'service_item_id'
);
SET @sql := IF(@time_entries_service_item_id_exists = 0, 'ALTER TABLE time_entries ADD COLUMN service_item_id INT NULL AFTER invoice_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @time_entries_invoice_item_id_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'invoice_item_id'
);
SET @sql := IF(@time_entries_invoice_item_id_exists = 0, 'ALTER TABLE time_entries ADD COLUMN invoice_item_id INT DEFAULT NULL AFTER rate', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @time_entries_updated_at_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'updated_at'
);
SET @sql := IF(@time_entries_updated_at_exists = 0, 'ALTER TABLE time_entries ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
