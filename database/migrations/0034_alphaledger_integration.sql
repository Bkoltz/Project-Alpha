-- Durable Project Alpha <-> AlphaLedger integration contract.
-- PA owns people/projects/assignments, billing, and pay-record status.
-- AL owns approved time entries, corrections, and pay-accrual snapshots.

CREATE TABLE IF NOT EXISTS pa_integration_identity (
    singleton TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    business_id CHAR(36) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_pa_integration_identity_singleton CHECK (singleton = 1),
    UNIQUE KEY uq_pa_integration_business_id (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO pa_integration_identity (singleton, business_id)
VALUES (1, LOWER(UUID()));

CREATE TABLE IF NOT EXISTS alphaledger_policy (
    singleton TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    approved_api_key_id INT NULL,
    approved_callback_url VARCHAR(2048) NULL,
    approved_callback_hash CHAR(64) NULL,
    allow_unrestricted_key TINYINT(1) NOT NULL DEFAULT 0,
    enabled_by INT NULL,
    enabled_at DATETIME NULL,
    disabled_by INT NULL,
    disabled_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_al_policy_singleton CHECK (singleton = 1),
    UNIQUE KEY uq_al_policy_callback_hash (approved_callback_hash),
    CONSTRAINT fk_al_policy_api_key FOREIGN KEY (approved_api_key_id) REFERENCES api_keys(id) ON DELETE RESTRICT,
    CONSTRAINT fk_al_policy_enabled_by FOREIGN KEY (enabled_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_al_policy_disabled_by FOREIGN KEY (disabled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO alphaledger_policy (singleton, enabled) VALUES (1, 0);

CREATE TABLE IF NOT EXISTS alphaledger_installations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id CHAR(36) NOT NULL,
    api_key_id INT NOT NULL,
    organization_id INT NULL,
    callback_url VARCHAR(2048) NOT NULL,
    callback_hash CHAR(64) NOT NULL,
    webhook_secret_enc TEXT NOT NULL,
    schema_version VARCHAR(20) NOT NULL DEFAULT '1.0',
    status ENUM('active','degraded','disabled','auth_failed') NOT NULL DEFAULT 'active',
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    last_success_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_al_installation_id (installation_id),
    UNIQUE KEY uq_al_installation_callback (api_key_id, callback_hash),
    INDEX idx_al_installation_status (status),
    CONSTRAINT fk_al_installation_api_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_installation_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alphaledger_object_state (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    object_type VARCHAR(40) NOT NULL,
    object_id VARCHAR(255) NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    payload_hash CHAR(64) NOT NULL,
    is_present TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_al_object_state (installation_id, object_type, object_id),
    CONSTRAINT fk_al_object_state_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alphaledger_events (
    sequence_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    event_id CHAR(36) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    aggregate_id VARCHAR(255) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    envelope JSON NOT NULL,
    delivery_state ENUM('pending','delivered','attention') NOT NULL DEFAULT 'pending',
    delivery_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME NULL,
    last_error TEXT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_al_event_id (event_id),
    INDEX idx_al_event_changes (installation_id, sequence_id),
    INDEX idx_al_event_delivery (delivery_state, next_attempt_at),
    CONSTRAINT fk_al_event_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alphaledger_idempotency (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT NOT NULL,
    idempotency_key VARCHAR(255) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    response_code SMALLINT UNSIGNED NULL,
    response_body JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL 30 DAY),
    UNIQUE KEY uq_al_idempotency_key (api_key_id, idempotency_key),
    INDEX idx_al_idempotency_expiry (expires_at),
    CONSTRAINT fk_al_idempotency_api_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alphaledger_received_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    event_id CHAR(36) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    aggregate_id VARCHAR(255) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    result JSON NOT NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_al_received_event (installation_id, event_id),
    INDEX idx_al_received_aggregate (installation_id, event_type, aggregate_id, revision),
    CONSTRAINT fk_al_received_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alphaledger_project_assignments (
    project_id INT NOT NULL,
    user_id INT NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id, user_id),
    CONSTRAINT fk_al_assignment_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_assignment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_assignment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @al_time_source_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='source_system');
SET @sql := IF(@al_time_source_exists=0, 'ALTER TABLE time_entries ADD COLUMN source_system VARCHAR(32) NULL AFTER id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @al_time_external_id_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='external_id');
SET @sql := IF(@al_time_external_id_exists=0, 'ALTER TABLE time_entries ADD COLUMN external_id VARCHAR(128) NULL AFTER source_system', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @al_time_external_revision_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='external_revision');
SET @sql := IF(@al_time_external_revision_exists=0, 'ALTER TABLE time_entries ADD COLUMN external_revision INT UNSIGNED NULL AFTER external_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @al_time_external_status_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='external_status');
SET @sql := IF(@al_time_external_status_exists=0, 'ALTER TABLE time_entries ADD COLUMN external_status VARCHAR(20) NULL AFTER external_revision', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @al_time_external_index_exists := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='time_entries' AND index_name='uq_time_entries_external');
SET @sql := IF(@al_time_external_index_exists=0, 'ALTER TABLE time_entries ADD UNIQUE KEY uq_time_entries_external (source_system, external_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS employee_pay_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    installation_id BIGINT UNSIGNED NOT NULL,
    external_id VARCHAR(128) NOT NULL,
    external_time_entry_id VARCHAR(128) NOT NULL,
    external_revision INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    hours DECIMAL(12,4) NOT NULL,
    rate DECIMAL(12,4) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    status ENUM('pending','paid','voided') NOT NULL DEFAULT 'pending',
    status_revision INT UNSIGNED NOT NULL DEFAULT 1,
    paid_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employee_pay_external (installation_id, external_id),
    INDEX idx_employee_pay_status (status, created_at),
    INDEX idx_employee_pay_user (user_id, created_at),
    CONSTRAINT fk_employee_pay_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_employee_pay_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE,
    CONSTRAINT fk_employee_pay_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alphaledger_sync_conflicts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    object_type VARCHAR(50) NOT NULL,
    object_id VARCHAR(128) NOT NULL,
    local_revision VARCHAR(64) NOT NULL DEFAULT '',
    remote_revision VARCHAR(64) NOT NULL DEFAULT '',
    reason VARCHAR(500) NOT NULL,
    details JSON NOT NULL,
    status ENUM('open','resolved') NOT NULL DEFAULT 'open',
    resolved_by INT NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_al_conflict_status (status, created_at),
    CONSTRAINT fk_al_conflict_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_conflict_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
