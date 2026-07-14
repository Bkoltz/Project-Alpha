-- Administrator-default workforce access, configurable mileage, and billable mileage invoicing.

INSERT INTO app_config (organization_id, config_key, config_value) VALUES
    (0, 'workforce_allow_non_admin_time_management', '0'),
    (0, 'workforce_allow_non_admin_time_approval', '0'),
    (0, 'default_mileage_rate', '0.670'),
    (0, 'default_mileage_include_return_trip', '1'),
    (0, 'default_mileage_bill_return_trip', '0')
ON DUPLICATE KEY UPDATE config_value = config_value;

ALTER TABLE contract_items MODIFY COLUMN billing_unit ENUM('each','hour','mile') NOT NULL DEFAULT 'each';
ALTER TABLE invoice_items MODIFY COLUMN billing_unit ENUM('each','hour','mile') NOT NULL DEFAULT 'each';

SET @has_mileage_bill_return := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='mileage_logs' AND column_name='bill_return_trip'
);
SET @sql := IF(@has_mileage_bill_return=0,
    'ALTER TABLE mileage_logs ADD COLUMN bill_return_trip TINYINT(1) NOT NULL DEFAULT 0 AFTER round_trip',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Legacy round trips were billed both directions. Preserve their behavior while
-- new entries use the separate, configurable billing default.
SET @sql := IF(@has_mileage_bill_return=0,
    'UPDATE mileage_logs SET bill_return_trip=1 WHERE round_trip=1',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_mileage_billed := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='mileage_logs' AND column_name='billed'
);
SET @sql := IF(@has_mileage_billed=0,
    'ALTER TABLE mileage_logs ADD COLUMN billed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_billable',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_mileage_invoice := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='mileage_logs' AND column_name='invoice_id'
);
SET @sql := IF(@has_mileage_invoice=0,
    'ALTER TABLE mileage_logs ADD COLUMN invoice_id INT NULL DEFAULT NULL AFTER billed',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_mileage_invoice_item := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='mileage_logs' AND column_name='invoice_item_id'
);
SET @sql := IF(@has_mileage_invoice_item=0,
    'ALTER TABLE mileage_logs ADD COLUMN invoice_item_id INT NULL DEFAULT NULL AFTER invoice_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_mileage_billable_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='mileage_logs' AND index_name='idx_mileage_billable_billed'
);
SET @sql := IF(@has_mileage_billable_index=0,
    'ALTER TABLE mileage_logs ADD INDEX idx_mileage_billable_billed (is_billable,billed)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_mileage_invoice_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='mileage_logs' AND index_name='idx_mileage_invoice'
);
SET @sql := IF(@has_mileage_invoice_index=0,
    'ALTER TABLE mileage_logs ADD INDEX idx_mileage_invoice (invoice_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_mileage_invoice_fk := (
    SELECT COUNT(*) FROM information_schema.referential_constraints
    WHERE constraint_schema=DATABASE() AND table_name='mileage_logs' AND constraint_name='fk_mileage_invoice'
);
SET @sql := IF(@has_mileage_invoice_fk=0,
    'ALTER TABLE mileage_logs ADD CONSTRAINT fk_mileage_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_mileage_invoice_item_fk := (
    SELECT COUNT(*) FROM information_schema.referential_constraints
    WHERE constraint_schema=DATABASE() AND table_name='mileage_logs' AND constraint_name='fk_mileage_invoice_item'
);
SET @sql := IF(@has_mileage_invoice_item_fk=0,
    'ALTER TABLE mileage_logs ADD CONSTRAINT fk_mileage_invoice_item FOREIGN KEY (invoice_item_id) REFERENCES invoice_items(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
