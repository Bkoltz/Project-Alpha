-- Align the built-in Workforce module with PA's account, settings, client,
-- project, and invoice domains. app_config is the single settings source;
-- business_settings remains only as an upgrade seed for existing installs.

INSERT INTO app_config (organization_id,config_key,config_value)
SELECT 0,'workforce_currency',currency FROM business_settings WHERE singleton=1
ON DUPLICATE KEY UPDATE config_value=app_config.config_value;

INSERT INTO app_config (organization_id,config_key,config_value)
SELECT 0,'workforce_default_hourly_rate',COALESCE(default_hourly_rate,'') FROM business_settings WHERE singleton=1
ON DUPLICATE KEY UPDATE config_value=app_config.config_value;

INSERT INTO app_config (organization_id,config_key,config_value)
SELECT 0,'workforce_default_billing_rate',COALESCE(default_billing_rate,'') FROM business_settings WHERE singleton=1
ON DUPLICATE KEY UPDATE config_value=app_config.config_value;

INSERT INTO app_config (organization_id,config_key,config_value)
SELECT 0,'workforce_require_project',IF(require_project=1,'1','0') FROM business_settings WHERE singleton=1
ON DUPLICATE KEY UPDATE config_value=app_config.config_value;

INSERT INTO app_config (organization_id,config_key,config_value)
SELECT 0,'workforce_require_description',IF(require_description=1,'1','0') FROM business_settings WHERE singleton=1
ON DUPLICATE KEY UPDATE config_value=app_config.config_value;

SET @work_time_client_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='work_time_entries' AND column_name='client_id'
);
SET @sql := IF(@work_time_client_exists=0,
    'ALTER TABLE work_time_entries ADD COLUMN client_id INT NULL AFTER user_id, ADD INDEX idx_work_time_client (client_id), ADD CONSTRAINT fk_work_time_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @work_time_invoice_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='work_time_entries' AND column_name='invoice_id'
);
SET @sql := IF(@work_time_invoice_exists=0,
    'ALTER TABLE work_time_entries ADD COLUMN invoice_id INT NULL AFTER project_id, ADD INDEX idx_work_time_invoice (invoice_id), ADD CONSTRAINT fk_work_time_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE work_time_entries t
JOIN projects p ON p.id=t.project_id
SET t.client_id=COALESCE(t.client_id,p.client_id)
WHERE t.client_id IS NULL;

SET @snapshot_client_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='work_approval_snapshots' AND column_name='client_id'
);
SET @sql := IF(@snapshot_client_exists=0,
    'ALTER TABLE work_approval_snapshots ADD COLUMN client_id INT NULL AFTER employee_name, ADD COLUMN client_name VARCHAR(255) NOT NULL DEFAULT '''' AFTER client_id, ADD INDEX idx_work_approval_client (client_id), ADD CONSTRAINT fk_work_approval_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @snapshot_invoice_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='work_approval_snapshots' AND column_name='invoice_id'
);
SET @sql := IF(@snapshot_invoice_exists=0,
    'ALTER TABLE work_approval_snapshots ADD COLUMN invoice_id INT NULL AFTER project_name, ADD COLUMN invoice_number VARCHAR(64) NOT NULL DEFAULT '''' AFTER invoice_id, ADD INDEX idx_work_approval_invoice (invoice_id), ADD CONSTRAINT fk_work_approval_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
