-- Separate physical mileage from client pricing and add GPS-ready tracking.

INSERT INTO app_config (organization_id, config_key, config_value) VALUES
    (0, 'default_mileage_included_miles', '0.000'),
    (0, 'default_mileage_charge_method', 'actual_trip'),
    (0, 'mileage_tracking_enabled', '0')
ON DUPLICATE KEY UPDATE config_value=VALUES(config_value);

SET @has_source := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='mileage_logs' AND column_name='source');
SET @sql := IF(@has_source=0, 'ALTER TABLE mileage_logs ADD COLUMN source ENUM(''manual'',''gps'') NOT NULL DEFAULT ''manual'' AFTER project_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_entry_mode := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='mileage_logs' AND column_name='entry_mode');
SET @sql := IF(@has_entry_mode=0, 'ALTER TABLE mileage_logs ADD COLUMN entry_mode ENUM(''simple'',''total_trip'') NOT NULL DEFAULT ''simple'' AFTER source', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_logged_miles := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='mileage_logs' AND column_name='logged_miles');
SET @sql := IF(@has_logged_miles=0, 'ALTER TABLE mileage_logs ADD COLUMN logged_miles DECIMAL(10,3) NULL AFTER miles', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_review_status := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='mileage_logs' AND column_name='review_status');
SET @sql := IF(@has_review_status=0, 'ALTER TABLE mileage_logs ADD COLUMN review_status ENUM(''draft'',''finalized'') NOT NULL DEFAULT ''finalized'' AFTER description', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE mileage_logs
SET logged_miles=ROUND(miles * CASE WHEN round_trip=1 THEN 2 ELSE 1 END, 3)
WHERE logged_miles IS NULL OR logged_miles <= 0;

CREATE TABLE IF NOT EXISTS service_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    client_id INT NULL,
    project_id INT NULL,
    name VARCHAR(150) NOT NULL,
    address_line1 VARCHAR(255) NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(100) NULL,
    postal_code VARCHAR(32) NULL,
    country VARCHAR(100) NULL DEFAULT 'US',
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_service_location_org (organization_id),
    INDEX idx_service_location_client (client_id),
    INDEX idx_service_location_project (project_id),
    CONSTRAINT fk_service_location_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_service_location_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_service_location_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_service_location_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_mileage_origins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    user_id INT NOT NULL,
    label VARCHAR(100) NOT NULL DEFAULT 'Billing origin',
    location_enc TEXT NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mileage_origin_org_user (organization_id,user_id),
    CONSTRAINT fk_mileage_origin_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_origin_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS travel_distance_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    origin_id INT NOT NULL,
    service_location_id INT NOT NULL,
    one_way_miles DECIMAL(10,3) NOT NULL,
    source ENUM('manual','routing') NOT NULL DEFAULT 'manual',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_travel_distance_pair (origin_id,service_location_id),
    CONSTRAINT fk_travel_distance_origin FOREIGN KEY (origin_id) REFERENCES user_mileage_origins(id) ON DELETE CASCADE,
    CONSTRAINT fk_travel_distance_location FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS travel_billing_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    scope_type ENUM('organization','client','quote','contract') NOT NULL,
    client_id INT NULL,
    quote_id INT NULL,
    contract_id INT NULL,
    charge_method ENUM('actual_trip','origin_distance','fixed_fee','none') NOT NULL DEFAULT 'actual_trip',
    mileage_rate DECIMAL(10,4) NOT NULL DEFAULT 0,
    included_miles DECIMAL(10,3) NOT NULL DEFAULT 0,
    charge_return TINYINT(1) NOT NULL DEFAULT 0,
    fixed_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    origin_id INT NULL,
    service_location_id INT NULL,
    estimated_one_way_miles DECIMAL(10,3) NULL,
    terms_text TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_travel_rule_scope (scope_type,organization_id,client_id,quote_id,contract_id),
    CONSTRAINT fk_travel_rule_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_travel_rule_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_travel_rule_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
    CONSTRAINT fk_travel_rule_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_travel_rule_origin FOREIGN KEY (origin_id) REFERENCES user_mileage_origins(id) ON DELETE SET NULL,
    CONSTRAINT fk_travel_rule_location FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE SET NULL,
    CONSTRAINT fk_travel_rule_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mileage_tracking_sessions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    user_id INT NOT NULL,
    status ENUM('active','draft_review','finalized','discarded') NOT NULL DEFAULT 'active',
    started_at DATETIME(3) NOT NULL,
    stopped_at DATETIME(3) NULL,
    finalized_at DATETIME(3) NULL,
    calculated_miles DECIMAL(10,3) NOT NULL DEFAULT 0,
    point_count INT NOT NULL DEFAULT 0,
    last_point_at DATETIME(3) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tracking_org_user_status (organization_id,user_id,status),
    INDEX idx_tracking_retention (status,finalized_at),
    CONSTRAINT fk_tracking_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_tracking_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mileage_tracking_points (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT NOT NULL,
    sequence_no INT NOT NULL,
    captured_at DATETIME(3) NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    accuracy_m DECIMAL(8,2) NULL,
    speed_mps DECIMAL(8,2) NULL,
    accepted TINYINT(1) NOT NULL DEFAULT 1,
    rejection_reason VARCHAR(60) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tracking_point_sequence (session_id,sequence_no),
    INDEX idx_tracking_point_time (session_id,captured_at),
    CONSTRAINT fk_tracking_point_session FOREIGN KEY (session_id) REFERENCES mileage_tracking_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_tracking_session := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='mileage_logs' AND column_name='tracking_session_id');
SET @sql := IF(@has_tracking_session=0, 'ALTER TABLE mileage_logs ADD COLUMN tracking_session_id BIGINT NULL AFTER logged_miles, ADD INDEX idx_mileage_tracking_session (tracking_session_id), ADD CONSTRAINT fk_mileage_tracking_session FOREIGN KEY (tracking_session_id) REFERENCES mileage_tracking_sessions(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS mileage_charge_allocations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    mileage_log_id INT NOT NULL,
    organization_id INT NULL,
    client_id INT NOT NULL,
    project_id INT NULL,
    contract_id INT NULL,
    service_location_id INT NULL,
    origin_id INT NULL,
    charge_method ENUM('actual_trip','origin_distance','fixed_fee') NOT NULL DEFAULT 'actual_trip',
    pricing_distance_miles DECIMAL(10,3) NOT NULL DEFAULT 0,
    included_miles DECIMAL(10,3) NOT NULL DEFAULT 0,
    charge_return TINYINT(1) NOT NULL DEFAULT 0,
    billable_miles DECIMAL(10,3) NOT NULL DEFAULT 0,
    mileage_rate DECIMAL(10,4) NOT NULL DEFAULT 0,
    fixed_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    client_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
    rule_snapshot JSON NULL,
    billed TINYINT(1) NOT NULL DEFAULT 0,
    invoice_id INT NULL,
    invoice_item_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mileage_allocation_log (mileage_log_id),
    INDEX idx_mileage_allocation_client_unbilled (client_id,billed),
    INDEX idx_mileage_allocation_invoice (invoice_id),
    CONSTRAINT fk_mileage_allocation_log FOREIGN KEY (mileage_log_id) REFERENCES mileage_logs(id) ON DELETE CASCADE,
    CONSTRAINT fk_mileage_allocation_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_allocation_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_mileage_allocation_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_allocation_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_allocation_location FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_allocation_origin FOREIGN KEY (origin_id) REFERENCES user_mileage_origins(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_allocation_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_allocation_invoice_item FOREIGN KEY (invoice_item_id) REFERENCES invoice_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO mileage_charge_allocations
    (mileage_log_id,organization_id,client_id,project_id,charge_method,pricing_distance_miles,included_miles,charge_return,billable_miles,mileage_rate,client_charge,billed,invoice_id,invoice_item_id,rule_snapshot)
SELECT m.id,m.organization_id,m.client_id,m.project_id,'actual_trip',m.miles,0,m.bill_return_trip,
       ROUND(m.miles * CASE WHEN m.round_trip=1 AND m.bill_return_trip=1 THEN 2 ELSE 1 END,3),
       m.mileage_rate,
       ROUND(m.miles * CASE WHEN m.round_trip=1 AND m.bill_return_trip=1 THEN 2 ELSE 1 END * m.mileage_rate,2),
       m.billed,m.invoice_id,m.invoice_item_id,
       JSON_OBJECT('migrated_from_legacy',TRUE,'round_trip',m.round_trip,'bill_return_trip',m.bill_return_trip)
FROM mileage_logs m
WHERE m.is_billable=1 AND m.client_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM mileage_charge_allocations a WHERE a.mileage_log_id=m.id);

ALTER TABLE quote_items MODIFY COLUMN billing_unit ENUM('each','hour','mile') NOT NULL DEFAULT 'each';

SET @has_quote_travel := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quote_items' AND column_name='is_travel');
SET @sql := IF(@has_quote_travel=0, 'ALTER TABLE quote_items ADD COLUMN is_travel TINYINT(1) NOT NULL DEFAULT 0 AFTER billing_unit, ADD COLUMN pricing_status ENUM(''standard'',''estimate'',''variable'') NOT NULL DEFAULT ''standard'' AFTER is_travel', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has_contract_travel := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='contract_items' AND column_name='is_travel');
SET @sql := IF(@has_contract_travel=0, 'ALTER TABLE contract_items ADD COLUMN is_travel TINYINT(1) NOT NULL DEFAULT 0 AFTER billing_unit, ADD COLUMN pricing_status ENUM(''standard'',''estimate'',''variable'') NOT NULL DEFAULT ''standard'' AFTER is_travel', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has_invoice_travel := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoice_items' AND column_name='is_travel');
SET @sql := IF(@has_invoice_travel=0, 'ALTER TABLE invoice_items ADD COLUMN is_travel TINYINT(1) NOT NULL DEFAULT 0 AFTER billing_unit, ADD COLUMN pricing_status ENUM(''standard'',''estimate'',''variable'') NOT NULL DEFAULT ''standard'' AFTER is_travel', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
