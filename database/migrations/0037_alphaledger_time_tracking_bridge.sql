-- Durable PA team members, AlphaLedger mappings, rates, exceptions, commands,
-- and source snapshots for connected time tracking.

CREATE TABLE IF NOT EXISTS team_members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    user_id INT NULL,
    display_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_team_member_user (user_id),
    INDEX idx_team_member_org_active (organization_id,is_active,display_name),
    INDEX idx_team_member_email (email),
    CONSTRAINT fk_team_member_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_team_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO team_members (user_id,display_name,email,is_active)
SELECT u.id,COALESCE(NULLIF(u.username,''),u.email),u.email,
       IF(u.is_disabled=0 AND u.deleted_at IS NULL,1,0)
FROM users u
LEFT JOIN team_members tm ON tm.user_id=u.id
WHERE tm.id IS NULL;

SET @al_install_business_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='alphaledger_installations' AND column_name='al_business_id');
SET @sql := IF(@al_install_business_exists=0, 'ALTER TABLE alphaledger_installations ADD COLUMN al_business_id CHAR(36) NULL AFTER installation_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @al_install_command_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='alphaledger_installations' AND column_name='command_api_url');
SET @sql := IF(@al_install_command_exists=0, 'ALTER TABLE alphaledger_installations ADD COLUMN command_api_url VARCHAR(2048) NULL AFTER callback_url', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @al_install_app_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='alphaledger_installations' AND column_name='al_app_url');
SET @sql := IF(@al_install_app_exists=0, 'ALTER TABLE alphaledger_installations ADD COLUMN al_app_url VARCHAR(2048) NULL AFTER command_api_url', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @al_install_capabilities_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='alphaledger_installations' AND column_name='capabilities');
SET @sql := IF(@al_install_capabilities_exists=0, 'ALTER TABLE alphaledger_installations ADD COLUMN capabilities JSON NULL AFTER schema_version', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @al_install_command_sync_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='alphaledger_installations' AND column_name='last_command_sync_at');
SET @sql := IF(@al_install_command_sync_exists=0, 'ALTER TABLE alphaledger_installations ADD COLUMN last_command_sync_at DATETIME NULL AFTER last_success_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS alphaledger_employee_mappings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    al_business_id CHAR(36) NOT NULL,
    al_employee_id CHAR(36) NOT NULL,
    team_member_id BIGINT UNSIGNED NOT NULL,
    confirmed_by INT NULL,
    confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_al_employee_mapping_external (al_business_id,al_employee_id),
    UNIQUE KEY uq_al_employee_mapping_member (al_business_id,team_member_id),
    CONSTRAINT fk_al_employee_mapping_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_employee_mapping_member FOREIGN KEY (team_member_id) REFERENCES team_members(id) ON DELETE RESTRICT,
    CONSTRAINT fk_al_employee_mapping_confirmer FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alphaledger_project_mappings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    al_business_id CHAR(36) NOT NULL,
    al_project_id CHAR(36) NOT NULL,
    project_id INT NOT NULL,
    confirmed_by INT NULL,
    confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_al_project_mapping_external (al_business_id,al_project_id),
    UNIQUE KEY uq_al_project_mapping_project (al_business_id,project_id),
    CONSTRAINT fk_al_project_mapping_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_project_mapping_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
    CONSTRAINT fk_al_project_mapping_confirmer FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS team_member_rates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_member_id BIGINT UNSIGNED NOT NULL,
    rate_type ENUM('cost','billing') NOT NULL,
    amount DECIMAL(12,4) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    effective_from DATE NOT NULL,
    effective_until DATE NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_team_member_rate_lookup (team_member_id,rate_type,effective_from,effective_until),
    CONSTRAINT fk_team_member_rate_member FOREIGN KEY (team_member_id) REFERENCES team_members(id) ON DELETE CASCADE,
    CONSTRAINT fk_team_member_rate_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_rate_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    scope_type ENUM('client','project') NOT NULL,
    client_id INT NULL,
    project_id INT NULL,
    amount DECIMAL(12,4) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    effective_from DATE NOT NULL,
    effective_until DATE NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_billing_rate_project (project_id,effective_from,effective_until),
    INDEX idx_billing_rate_client (client_id,effective_from,effective_until),
    CONSTRAINT fk_billing_rate_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_rate_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_rate_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_rate_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alphaledger_integration_exceptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    exception_type ENUM('unmapped_employee','unmapped_project','missing_rate','rejected_command','validation_failure','backfill_failure') NOT NULL,
    source_object_type VARCHAR(50) NOT NULL,
    source_object_id VARCHAR(128) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    details JSON NOT NULL,
    status ENUM('open','resolved','cancelled') NOT NULL DEFAULT 'open',
    occurrences INT UNSIGNED NOT NULL DEFAULT 1,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_by INT NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_al_exception_object (installation_id,exception_type,source_object_type,source_object_id,status),
    INDEX idx_al_exception_status (status,exception_type,last_seen_at),
    CONSTRAINT fk_al_exception_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_exception_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alphaledger_command_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    operation_id CHAR(36) NOT NULL,
    idempotency_key VARCHAR(255) NOT NULL,
    operation_type ENUM('start','stop','create','update','assign','submit','cancel','backfill_preview','backfill_request') NOT NULL,
    actor_user_id INT NOT NULL,
    team_member_id BIGINT UNSIGNED NOT NULL,
    al_employee_id CHAR(36) NOT NULL,
    al_entry_id CHAR(36) NULL,
    started_at DATETIME NULL,
    ended_at DATETIME NULL,
    payload_enc TEXT NOT NULL,
    state ENUM('pending','delivered','attention','cancelled') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_al_command_operation (operation_id),
    UNIQUE KEY uq_al_command_idempotency (installation_id,idempotency_key),
    INDEX idx_al_command_delivery (state,next_attempt_at,id),
    INDEX idx_al_command_actor (actor_user_id,state,created_at),
    CONSTRAINT fk_al_command_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_command_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_al_command_member FOREIGN KEY (team_member_id) REFERENCES team_members(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alphaledger_backfill_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    external_backfill_id VARCHAR(128) NULL,
    requested_by INT NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    state ENUM('previewed','requested','running','completed','failed') NOT NULL DEFAULT 'previewed',
    preview_count INT UNSIGNED NULL,
    imported_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    requested_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_al_backfill_state (state,created_at),
    CONSTRAINT fk_al_backfill_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_backfill_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @pay_team_member_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employee_pay_records' AND column_name='team_member_id');
SET @sql := IF(@pay_team_member_exists=0, 'ALTER TABLE employee_pay_records ADD COLUMN team_member_id BIGINT UNSIGNED NULL AFTER user_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE employee_pay_records e
JOIN team_members tm ON tm.user_id=e.user_id
SET e.team_member_id=tm.id
WHERE e.team_member_id IS NULL;

SET @te_team_member_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='team_member_id');
SET @sql := IF(@te_team_member_exists=0, 'ALTER TABLE time_entries ADD COLUMN team_member_id BIGINT UNSIGNED NULL AFTER user_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @te_al_business_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='al_business_id');
SET @sql := IF(@te_al_business_exists=0, 'ALTER TABLE time_entries ADD COLUMN al_business_id CHAR(36) NULL AFTER external_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @te_source_entry_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='source_entry_id');
SET @sql := IF(@te_source_entry_exists=0, 'ALTER TABLE time_entries ADD COLUMN source_entry_id VARCHAR(128) NULL AFTER al_business_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @te_source_updated_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='source_updated_at');
SET @sql := IF(@te_source_updated_exists=0, 'ALTER TABLE time_entries ADD COLUMN source_updated_at DATETIME NULL AFTER source_entry_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @te_imported_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='imported_at');
SET @sql := IF(@te_imported_exists=0, 'ALTER TABLE time_entries ADD COLUMN imported_at DATETIME NULL AFTER source_updated_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @te_cost_snapshot_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='cost_rate_snapshot');
SET @sql := IF(@te_cost_snapshot_exists=0, 'ALTER TABLE time_entries ADD COLUMN cost_rate_snapshot DECIMAL(12,4) NULL AFTER rate', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @te_billing_snapshot_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='billing_rate_snapshot');
SET @sql := IF(@te_billing_snapshot_exists=0, 'ALTER TABLE time_entries ADD COLUMN billing_rate_snapshot DECIMAL(12,4) NULL AFTER cost_rate_snapshot', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @te_currency_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='currency');
SET @sql := IF(@te_currency_exists=0, 'ALTER TABLE time_entries ADD COLUMN currency CHAR(3) NOT NULL DEFAULT ''USD'' AFTER billing_rate_snapshot', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @te_snapshot_source_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='rate_snapshot_source');
SET @sql := IF(@te_snapshot_source_exists=0, 'ALTER TABLE time_entries ADD COLUMN rate_snapshot_source VARCHAR(50) NULL AFTER currency', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE time_entries te
JOIN team_members tm ON tm.user_id=te.user_id
SET te.team_member_id=tm.id
WHERE te.team_member_id IS NULL;

UPDATE time_entries SET source_entry_id=external_id WHERE source_system='alphaledger' AND source_entry_id IS NULL;
UPDATE time_entries te
JOIN (SELECT al_business_id FROM alphaledger_installations WHERE al_business_id IS NOT NULL ORDER BY id DESC LIMIT 1) i
SET te.al_business_id=i.al_business_id
WHERE te.source_system='alphaledger' AND te.al_business_id IS NULL;

SET @te_user_nullable := (SELECT IS_NULLABLE='YES' FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='user_id');
SET @sql := IF(@te_user_nullable=0, 'ALTER TABLE time_entries MODIFY COLUMN user_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @old_external_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='time_entries' AND index_name='uq_time_entries_external');
SET @sql := IF(@old_external_index>0, 'ALTER TABLE time_entries DROP INDEX uq_time_entries_external', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @new_source_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='time_entries' AND index_name='uq_time_entries_al_source');
SET @sql := IF(@new_source_index=0, 'ALTER TABLE time_entries ADD UNIQUE KEY uq_time_entries_al_source (source_system,al_business_id,source_entry_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO alphaledger_employee_mappings (installation_id,al_business_id,al_employee_id,team_member_id)
SELECT p.installation_id,i.al_business_id,p.external_id,tm.id
FROM alphaledger_ledger_people p
JOIN alphaledger_installations i ON i.id=p.installation_id AND i.al_business_id IS NOT NULL
JOIN users u ON (CAST(u.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci)=p.pa_person_id
JOIN team_members tm ON tm.user_id=u.id
WHERE p.pa_person_id IS NOT NULL AND p.deleted_at IS NULL;

INSERT IGNORE INTO alphaledger_project_mappings (installation_id,al_business_id,al_project_id,project_id)
SELECT lp.installation_id,i.al_business_id,lp.external_id,p.id
FROM alphaledger_ledger_projects lp
JOIN alphaledger_installations i ON i.id=lp.installation_id AND i.al_business_id IS NOT NULL
JOIN projects p ON (CAST(p.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci)=lp.pa_project_id
WHERE lp.pa_project_id IS NOT NULL AND lp.deleted_at IS NULL;
