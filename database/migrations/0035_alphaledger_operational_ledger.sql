-- AlphaLedger operational ledger read model for Project Alpha.
-- This is deliberately separate from PA-owned accounting and native time entries.

SET @al_install_last_ledger_sync_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='alphaledger_installations' AND column_name='last_ledger_sync_at');
SET @sql := IF(@al_install_last_ledger_sync_exists=0, 'ALTER TABLE alphaledger_installations ADD COLUMN last_ledger_sync_at DATETIME NULL AFTER last_success_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS alphaledger_ledger_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    snapshot_id CHAR(36) NOT NULL,
    state ENUM('receiving','complete','failed') NOT NULL DEFAULT 'receiving',
    records_received INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_al_ledger_snapshot (installation_id, snapshot_id),
    INDEX idx_al_ledger_snapshot_state (state, created_at),
    CONSTRAINT fk_al_ledger_snapshot_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alphaledger_ledger_people (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    organization_id INT NULL,
    external_id CHAR(36) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    email VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    role ENUM('admin','employee') NOT NULL,
    pa_person_id VARCHAR(128) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    occurred_at DATETIME NOT NULL,
    snapshot_id CHAR(36) NULL,
    deleted_at DATETIME NULL,
    raw_data JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_al_ledger_person (installation_id, external_id),
    INDEX idx_al_ledger_people_active (installation_id, is_active, display_name),
    CONSTRAINT fk_al_ledger_people_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_ledger_people_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alphaledger_ledger_projects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    organization_id INT NULL,
    external_id CHAR(36) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    pa_project_id VARCHAR(128) NULL,
    name VARCHAR(255) NOT NULL,
    origin ENUM('internal','pa') NOT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    occurred_at DATETIME NOT NULL,
    snapshot_id CHAR(36) NULL,
    deleted_at DATETIME NULL,
    raw_data JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_al_ledger_project (installation_id, external_id),
    INDEX idx_al_ledger_projects_active (installation_id, is_archived, name),
    CONSTRAINT fk_al_ledger_projects_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_ledger_projects_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alphaledger_ledger_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    organization_id INT NULL,
    external_id VARCHAR(128) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    project_external_id CHAR(36) NOT NULL,
    employee_external_id CHAR(36) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    occurred_at DATETIME NOT NULL,
    snapshot_id CHAR(36) NULL,
    deleted_at DATETIME NULL,
    raw_data JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_al_ledger_assignment (installation_id, external_id),
    INDEX idx_al_ledger_assignment_project (installation_id, project_external_id, is_active),
    INDEX idx_al_ledger_assignment_employee (installation_id, employee_external_id, is_active),
    CONSTRAINT fk_al_ledger_assignments_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_ledger_assignments_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alphaledger_ledger_time_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    organization_id INT NULL,
    external_id CHAR(36) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    employee_external_id CHAR(36) NOT NULL,
    project_external_id CHAR(36) NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NULL,
    duration_seconds BIGINT UNSIGNED NULL,
    description TEXT NOT NULL,
    billable TINYINT(1) NOT NULL DEFAULT 0,
    is_payable TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('running','review','approved','rejected','voided') NOT NULL,
    rejection_reason TEXT NOT NULL,
    reviewed_at DATETIME NULL,
    occurred_at DATETIME NOT NULL,
    snapshot_id CHAR(36) NULL,
    deleted_at DATETIME NULL,
    raw_data JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_al_ledger_time_entry (installation_id, external_id),
    INDEX idx_al_ledger_time_status (installation_id, status, start_time),
    INDEX idx_al_ledger_time_employee (installation_id, employee_external_id, start_time),
    INDEX idx_al_ledger_time_project (installation_id, project_external_id, start_time),
    CONSTRAINT fk_al_ledger_time_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_ledger_time_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alphaledger_ledger_breaks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    organization_id INT NULL,
    external_id CHAR(36) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    time_entry_external_id CHAR(36) NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NULL,
    duration_seconds BIGINT UNSIGNED NULL,
    occurred_at DATETIME NOT NULL,
    snapshot_id CHAR(36) NULL,
    deleted_at DATETIME NULL,
    raw_data JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_al_ledger_break (installation_id, external_id),
    INDEX idx_al_ledger_break_entry (installation_id, time_entry_external_id, start_time),
    CONSTRAINT fk_al_ledger_break_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_ledger_break_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alphaledger_ledger_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    organization_id INT NULL,
    external_id CHAR(36) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    time_entry_external_id CHAR(36) NOT NULL,
    entry_revision INT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    created_by_external_id CHAR(36) NOT NULL,
    revision_snapshot JSON NOT NULL,
    occurred_at DATETIME NOT NULL,
    snapshot_id CHAR(36) NULL,
    deleted_at DATETIME NULL,
    raw_data JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_al_ledger_revision (installation_id, external_id),
    INDEX idx_al_ledger_revision_entry (installation_id, time_entry_external_id, entry_revision),
    CONSTRAINT fk_al_ledger_revision_installation FOREIGN KEY (installation_id) REFERENCES alphaledger_installations(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_ledger_revision_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @al_pay_user_nullable := (SELECT IS_NULLABLE='YES' FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employee_pay_records' AND column_name='user_id');
SET @sql := IF(@al_pay_user_nullable=0, 'ALTER TABLE employee_pay_records MODIFY COLUMN user_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @al_pay_external_employee_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employee_pay_records' AND column_name='external_employee_id');
SET @sql := IF(@al_pay_external_employee_exists=0, 'ALTER TABLE employee_pay_records ADD COLUMN external_employee_id CHAR(36) NULL AFTER external_revision', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @al_pay_employee_name_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employee_pay_records' AND column_name='employee_name_snapshot');
SET @sql := IF(@al_pay_employee_name_exists=0, 'ALTER TABLE employee_pay_records ADD COLUMN employee_name_snapshot VARCHAR(255) NOT NULL DEFAULT '''' AFTER external_employee_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @al_pay_accrued_at_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employee_pay_records' AND column_name='accrued_at');
SET @sql := IF(@al_pay_accrued_at_exists=0, 'ALTER TABLE employee_pay_records ADD COLUMN accrued_at DATETIME NULL AFTER paid_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @al_pay_payload_hash_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employee_pay_records' AND column_name='payload_hash');
SET @sql := IF(@al_pay_payload_hash_exists=0, 'ALTER TABLE employee_pay_records ADD COLUMN payload_hash CHAR(64) NULL AFTER external_revision', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @al_pay_snapshot_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employee_pay_records' AND column_name='ledger_snapshot_id');
SET @sql := IF(@al_pay_snapshot_exists=0, 'ALTER TABLE employee_pay_records ADD COLUMN ledger_snapshot_id CHAR(36) NULL AFTER accrued_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @al_pay_deleted_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employee_pay_records' AND column_name='deleted_at');
SET @sql := IF(@al_pay_deleted_exists=0, 'ALTER TABLE employee_pay_records ADD COLUMN deleted_at DATETIME NULL AFTER ledger_snapshot_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
