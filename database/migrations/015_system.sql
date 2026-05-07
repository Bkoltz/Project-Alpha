-- ============================================================================
-- SYSTEM (Audit, Notifications, Webhooks, Jobs)
-- ============================================================================

  IF NOT EXISTS document_custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_type ENUM ('regular', 'long_term', 'on_demand') NOT NULL,
    field_key VARCHAR(100) NOT NULL COMMENT 'Internal key like pickup_date',
    field_label VARCHAR(255) NOT NULL COMMENT 'Display label like Pick Up Date',
    field_type ENUM ('text', 'date', 'number', 'textarea', 'select') NOT NULL DEFAULT 'text',
    field_options JSON NULL COMMENT 'For select fields, array of options',
    is_required TINYINT (1) NOT NULL DEFAULT 0,
    is_builtin TINYINT (1) NOT NULL DEFAULT 0 COMMENT 'Built-in fields cannot be deleted',
    is_enabled TINYINT (1) NOT NULL DEFAULT 1 COMMENT 'Whether field is shown',
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_field_key_type (document_type, field_key),
    INDEX idx_doc_type_enabled (document_type, is_enabled),
    INDEX idx_display_order (document_type, display_order)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Seed built-in fields for all document types
-- Deposit field (composite: type + value, handled specially in UI)
INSERT IGNORE INTO document_custom_fields (
  document_type,
  field_key,
  field_label,
  field_type,
  is_required,
  is_builtin,
  is_enabled,
  display_order
)
VALUES
  (
    'regular',
    'deposit',
    'Deposit Required',
    'text',
    0,
    1,
    1,
    1
  ),
  (
    'long_term',
    'deposit',
    'Deposit Required',
    'text',
    0,
    1,
    1,
    1
  ),
  (
    'on_demand',
    'deposit',
    'Deposit Required',
    'text',
    0,
    1,
    1,
    1
  );

-- Fulfillment date field
INSERT IGNORE INTO document_custom_fields (
  document_type,
  field_key,
  field_label,
  field_type,
  is_required,
  is_builtin,
  is_enabled,
  display_order
)
VALUES
  (
    'regular',
    'fulfillment_date',
    'Fulfillment Date (Estimated)',
    'date',
    0,
    1,
    1,
    2
  ),
  (
    'long_term',
    'fulfillment_date',
    'Fulfillment Date (Estimated)',
    'date',
    0,
    1,
    1,
    2
  ),
  (
    'on_demand',
    'fulfillment_date',
    'Fulfillment Date (Estimated)',
    'date',
    0,
    1,
    1,
    2
  );

  IF NOT EXISTS notification_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    is_enabled TINYINT (1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_setting (setting_key)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS notification_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    notification_type VARCHAR(100) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NULL,
    entity_type VARCHAR(50) NULL,
    entity_id INT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM ('sent', 'failed', 'pending') DEFAULT 'sent',
    error_message TEXT NULL,
    INDEX idx_notif_type (notification_type),
    INDEX idx_notif_entity (entity_type, entity_id),
    INDEX idx_notif_sent (sent_at)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

  IF NOT EXISTS activity_log (
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
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

  IF NOT EXISTS cron_job_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL UNIQUE,
    last_run DATETIME NULL,
    status ENUM ('success', 'failed') NOT NULL DEFAULT 'success',
    error_message TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_job_name (job_name)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

  IF NOT EXISTS app_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_config_key (config_key)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

  IF NOT EXISTS fips_counties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    state_abbr VARCHAR(2) NOT NULL,
    county_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_fips (state_fips, county_fips),
    INDEX idx_state (state_fips),
    INDEX idx_state_abbr (state_abbr)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

CREATE TABLE
  IF NOT EXISTS tax_jurisdictions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    jurisdiction_code VARCHAR(10) DEFAULT NULL,
    jurisdiction_type ENUM ('state', 'county', 'city', 'special') NOT NULL DEFAULT 'county',
    state_rate DECIMAL(8, 6) NOT NULL DEFAULT 0,
    county_rate DECIMAL(8, 6) NOT NULL DEFAULT 0,
    city_rate DECIMAL(8, 6) NOT NULL DEFAULT 0,
    special_rate DECIMAL(8, 6) NOT NULL DEFAULT 0,
    total_rate DECIMAL(8, 4) NOT NULL DEFAULT 0,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    is_active TINYINT (1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_jurisdiction (state_fips, county_fips, jurisdiction_code, start_date),
    INDEX idx_state (state_fips),
    INDEX idx_county (state_fips, county_fips),
    INDEX idx_jurisdiction (jurisdiction_code),
    INDEX idx_active (is_active),
    INDEX idx_dates (start_date, end_date)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

CREATE TABLE
  IF NOT EXISTS tax_boundaries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zip5_start VARCHAR(5) NOT NULL,
    zip4_start VARCHAR(4) NOT NULL,
    zip5_end VARCHAR(5) NOT NULL,
    zip4_end VARCHAR(4) NOT NULL,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    jurisdiction_code VARCHAR(10) DEFAULT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_zip5 (zip5_start),
    INDEX idx_zip_range (zip5_start, zip4_start, zip5_end, zip4_end),
    INDEX idx_state_county (state_fips, county_fips),
    INDEX idx_jurisdiction (jurisdiction_code),
    INDEX idx_dates (start_date, end_date)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

CREATE TABLE
  IF NOT EXISTS tax_zip_complexity (
    zip5 VARCHAR(5) PRIMARY KEY,
    is_complex TINYINT (1) NOT NULL DEFAULT 0,
    reason VARCHAR(50) DEFAULT NULL,
    state_fips VARCHAR(2) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_complex (is_complex),
    INDEX idx_state (state_fips)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

INSERT INTO organizations (id, name, notes, created_at, updated_at)
VALUES
  (
    1,
    'Default organization for system (delete if not needed)',
    NOW(),
    NOW()
  );

-- Insert default admin user (username: admin, password: admin123)
-- Password hash is bcrypt hash of 'admin123'
INSERT INTO users (
  id,
  email,
  username,
  password_hash,
  role,
  created_at,
  updated_at
)
VALUES
  (
    1,
    'admin@localhost',
    'admin',
    '{{ADMIN_PASSWORD_HASH}}',
    'admin',
    NOW(),
    NOW()
  );

-- Seed app_config defaults
INSERT INTO app_config (config_key, config_value) VALUES
('link_resolver_enabled', '0'),
('default_link_expiration_days', '365'),
('org_level_links_only', '0'),
('link_expiration_checker', '0'),
('link_expiration_email_enabled', '0')
ON DUPLICATE KEY UPDATE config_value = config_value;

-- Seed cron_job_runs entries
INSERT IGNORE INTO cron_job_runs (job_name, last_run, status) VALUES
('generate_recurring_invoices', NULL, 'success'),
('send_invoice_reminders', NULL, 'success'),
('auto_terminate_contracts', NULL, 'success'),
('link_expiration_checker', NULL, 'success'),
('stripe_reconciliation', NULL, 'success');
