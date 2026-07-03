-- Repair legacy installs that predate the activity_log table and normalized API key columns.
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    document_type VARCHAR(20) NULL,
    document_id INT NULL,
    client_id INT NULL,
    description TEXT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_type (event_type),
    INDEX idx_activity_doc (document_type, document_id),
    INDEX idx_activity_client (client_id),
    INDEX idx_activity_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @api_keys_item_name_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'item_name'
);

SET @api_keys_name_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'name'
);

SET @sql := IF(
    @api_keys_item_name_exists = 1 AND @api_keys_name_exists = 0,
    'ALTER TABLE api_keys CHANGE COLUMN item_name name VARCHAR(255) NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @api_keys_name_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'name'
);

SET @sql := IF(
    @api_keys_name_exists = 0,
    'ALTER TABLE api_keys ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT '''' AFTER id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @payments_contract_id_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'payments'
      AND column_name = 'contract_id'
);

SET @sql := IF(
    @payments_contract_id_exists = 0,
    'ALTER TABLE payments ADD COLUMN contract_id INT NULL AFTER invoice_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @payments_reference_number_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'payments'
      AND column_name = 'reference_number'
);

SET @sql := IF(
    @payments_reference_number_exists = 0,
    'ALTER TABLE payments ADD COLUMN reference_number VARCHAR(255) NULL AFTER payment_date',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @payments_refunded_amount_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'payments'
      AND column_name = 'refunded_amount'
);

SET @sql := IF(
    @payments_refunded_amount_exists = 0,
    'ALTER TABLE payments ADD COLUMN refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @payments_disputed_amount_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'payments'
      AND column_name = 'disputed_amount'
);

SET @sql := IF(
    @payments_disputed_amount_exists = 0,
    'ALTER TABLE payments ADD COLUMN disputed_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER refunded_amount',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @payments_organization_id_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'payments'
      AND column_name = 'organization_id'
);

SET @sql := IF(
    @payments_organization_id_exists = 0,
    'ALTER TABLE payments ADD COLUMN organization_id INT NULL AFTER contract_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
