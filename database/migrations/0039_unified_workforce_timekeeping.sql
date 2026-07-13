-- Consolidate AlphaLedger's workforce, timekeeping, approval, and employee-pay
-- domains into Project Alpha. Existing PA time_entries remain the billing
-- projection/history table; authoritative employee time uses work_* tables.

ALTER TABLE users
    MODIFY COLUMN role ENUM('admin','owner','staff','member','employee','user') NOT NULL DEFAULT 'member';

CREATE TABLE IF NOT EXISTS business_settings (
    singleton TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    business_uuid CHAR(36) NOT NULL,
    business_name VARCHAR(190) NOT NULL DEFAULT 'My Business',
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    default_hourly_rate DECIMAL(12,4) NULL,
    default_billing_rate DECIMAL(12,4) NULL,
    require_project TINYINT(1) NOT NULL DEFAULT 0,
    require_description TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_business_settings_uuid (business_uuid),
    CONSTRAINT chk_business_settings_singleton CHECK (singleton = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO business_settings (singleton,business_uuid,business_name,timezone,currency)
SELECT 1,UUID(),COALESCE(NULLIF((SELECT config_value FROM app_config WHERE config_key='business_name' LIMIT 1),''),'My Business'),'UTC','USD'
WHERE NOT EXISTS (SELECT 1 FROM business_settings WHERE singleton=1);

CREATE TABLE IF NOT EXISTS employee_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    first_name VARCHAR(100) NOT NULL DEFAULT '',
    last_name VARCHAR(100) NOT NULL DEFAULT '',
    employment_status ENUM('active','inactive','terminated') NOT NULL DEFAULT 'active',
    hourly_rate DECIMAL(12,4) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    employee_can_view_pay TINYINT(1) NOT NULL DEFAULT 1,
    hired_at DATE NULL,
    terminated_at DATE NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_employee_profiles_user (user_id),
    INDEX idx_employee_profiles_status (employment_status),
    CONSTRAINT fk_employee_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO employee_profiles (user_id,first_name,last_name,employment_status,currency)
SELECT u.id,COALESCE(NULLIF(u.username,''),SUBSTRING_INDEX(u.email,'@',1)),'',
       CASE WHEN u.is_disabled=1 OR u.deleted_at IS NOT NULL THEN 'inactive' ELSE 'active' END,
       (SELECT currency FROM business_settings WHERE singleton=1)
FROM users u
JOIN team_members tm ON tm.user_id=u.id
WHERE NOT EXISTS (SELECT 1 FROM employee_profiles ep WHERE ep.user_id=u.id);

CREATE TABLE IF NOT EXISTS project_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    user_id INT NOT NULL,
    pay_rate_override DECIMAL(12,4) NULL,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ends_at DATETIME(6) NULL,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_project_assignments_project_user (project_id,user_id),
    INDEX idx_project_assignments_user_active (user_id,ends_at),
    CONSTRAINT fk_project_assignments_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_assignments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_assignments_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO project_assignments (project_id,user_id,created_by)
SELECT ata.project_id,tm.user_id,ata.created_by
FROM alphaledger_team_assignments ata
JOIN team_members tm ON tm.id=ata.team_member_id
WHERE tm.user_id IS NOT NULL
ON DUPLICATE KEY UPDATE updated_at=project_assignments.updated_at;

CREATE TABLE IF NOT EXISTS work_time_entries (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    project_id INT NULL,
    start_time DATETIME(6) NOT NULL,
    end_time DATETIME(6) NULL,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    description TEXT NOT NULL,
    tags JSON NOT NULL,
    billable TINYINT(1) NOT NULL DEFAULT 0,
    is_payable TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('running','review','rejected','approved','voided','cancelled') NOT NULL DEFAULT 'review',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    rejection_reason VARCHAR(1000) NOT NULL DEFAULT '',
    reviewed_by INT NULL,
    reviewed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_work_time_user_start (user_id,start_time),
    INDEX idx_work_time_project_status (project_id,status),
    INDEX idx_work_time_review (status,start_time),
    CONSTRAINT fk_work_time_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_work_time_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_work_time_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_time_breaks (
    id CHAR(36) NOT NULL PRIMARY KEY,
    time_entry_id CHAR(36) NOT NULL,
    start_time DATETIME(6) NOT NULL,
    end_time DATETIME(6) NULL,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_work_break_entry (time_entry_id,start_time),
    CONSTRAINT fk_work_break_entry FOREIGN KEY (time_entry_id) REFERENCES work_time_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_timer_locks (
    user_id INT NOT NULL PRIMARY KEY,
    time_entry_id CHAR(36) NOT NULL UNIQUE,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_work_timer_lock_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_work_timer_lock_entry FOREIGN KEY (time_entry_id) REFERENCES work_time_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_break_locks (
    time_entry_id CHAR(36) NOT NULL PRIMARY KEY,
    break_id CHAR(36) NOT NULL UNIQUE,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_work_break_lock_entry FOREIGN KEY (time_entry_id) REFERENCES work_time_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_work_break_lock_break FOREIGN KEY (break_id) REFERENCES work_time_breaks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_time_revisions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    time_entry_id CHAR(36) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    snapshot JSON NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_work_time_revision (time_entry_id,revision),
    CONSTRAINT fk_work_revision_entry FOREIGN KEY (time_entry_id) REFERENCES work_time_entries(id) ON DELETE RESTRICT,
    CONSTRAINT fk_work_revision_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_approval_snapshots (
    id CHAR(36) NOT NULL PRIMARY KEY,
    time_entry_id CHAR(36) NOT NULL,
    entry_revision INT UNSIGNED NOT NULL,
    employee_user_id INT NOT NULL,
    employee_name VARCHAR(255) NOT NULL,
    project_id INT NULL,
    project_name VARCHAR(255) NOT NULL DEFAULT '',
    start_time DATETIME(6) NOT NULL,
    end_time DATETIME(6) NOT NULL,
    duration_seconds INT UNSIGNED NOT NULL,
    description TEXT NOT NULL,
    billable TINYINT(1) NOT NULL,
    is_payable TINYINT(1) NOT NULL,
    pay_rate DECIMAL(12,4) NULL,
    billing_rate DECIMAL(12,4) NULL,
    pay_amount DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL,
    approved_by INT NOT NULL,
    approved_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    voided_at DATETIME(6) NULL,
    voided_by INT NULL,
    void_reason VARCHAR(1000) NULL,
    UNIQUE KEY uq_work_approval_entry_revision (time_entry_id,entry_revision),
    INDEX idx_work_approval_employee (employee_user_id,approved_at),
    CONSTRAINT fk_work_approval_entry FOREIGN KEY (time_entry_id) REFERENCES work_time_entries(id) ON DELETE RESTRICT,
    CONSTRAINT fk_work_approval_employee FOREIGN KEY (employee_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_work_approval_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_work_approval_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_work_approval_voider FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_pay_accruals (
    id CHAR(36) NOT NULL PRIMARY KEY,
    approval_snapshot_id CHAR(36) NULL,
    employee_user_id INT NOT NULL,
    employee_name VARCHAR(255) NOT NULL,
    hours DECIMAL(12,4) NOT NULL,
    rate DECIMAL(12,4) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL,
    status ENUM('pending','paid','voided') NOT NULL DEFAULT 'pending',
    paid_at DATETIME(6) NULL,
    historical_source VARCHAR(50) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_work_pay_approval (approval_snapshot_id),
    INDEX idx_work_pay_employee_status (employee_user_id,status),
    CONSTRAINT fk_work_pay_approval FOREIGN KEY (approval_snapshot_id) REFERENCES work_approval_snapshots(id) ON DELETE RESTRICT,
    CONSTRAINT fk_work_pay_employee FOREIGN KEY (employee_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_billing_consumptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    approval_snapshot_id CHAR(36) NOT NULL,
    billing_time_entry_id INT NOT NULL,
    consumption_type ENUM('approved','correction','reversal','void') NOT NULL DEFAULT 'approved',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_work_billing_approval_type (approval_snapshot_id,consumption_type),
    INDEX idx_work_billing_time_entry (billing_time_entry_id),
    CONSTRAINT fk_work_billing_approval FOREIGN KEY (approval_snapshot_id) REFERENCES work_approval_snapshots(id) ON DELETE RESTRICT,
    CONSTRAINT fk_work_billing_time FOREIGN KEY (billing_time_entry_id) REFERENCES time_entries(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_sessions (
    session_hash CHAR(64) NOT NULL PRIMARY KEY,
    user_id INT NULL,
    payload MEDIUMBLOB NOT NULL,
    last_activity_at DATETIME(6) NOT NULL,
    absolute_expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_app_sessions_user (user_id),
    INDEX idx_app_sessions_expiry (absolute_expires_at,last_activity_at),
    CONSTRAINT fk_app_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS background_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue_name VARCHAR(50) NOT NULL DEFAULT 'default',
    job_type VARCHAR(100) NOT NULL,
    payload JSON NOT NULL,
    state ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    reserved_at DATETIME(6) NULL,
    completed_at DATETIME(6) NULL,
    last_error VARCHAR(2000) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_background_jobs_claim (queue_name,state,available_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MySQL auto-commits DDL, so guard each addition to make a partially applied
-- migration safe to resume after an interrupted deployment.
SET @audit_entity_uuid_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='system_audit' AND column_name='entity_uuid'
);
SET @sql := IF(@audit_entity_uuid_exists=0,
    'ALTER TABLE system_audit ADD COLUMN entity_uuid CHAR(36) NULL AFTER entity_id',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @audit_before_data_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='system_audit' AND column_name='before_data'
);
SET @sql := IF(@audit_before_data_exists=0,
    'ALTER TABLE system_audit ADD COLUMN before_data JSON NULL AFTER details',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @audit_after_data_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='system_audit' AND column_name='after_data'
);
SET @sql := IF(@audit_after_data_exists=0,
    'ALTER TABLE system_audit ADD COLUMN after_data JSON NULL AFTER before_data',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @audit_correlation_id_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='system_audit' AND column_name='correlation_id'
);
SET @sql := IF(@audit_correlation_id_exists=0,
    'ALTER TABLE system_audit ADD COLUMN correlation_id CHAR(36) NULL AFTER after_data',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @audit_entity_uuid_index_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='system_audit' AND index_name='idx_audit_entity_uuid'
);
SET @sql := IF(@audit_entity_uuid_index_exists=0,
    'ALTER TABLE system_audit ADD INDEX idx_audit_entity_uuid (entity_type,entity_uuid)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @audit_correlation_index_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='system_audit' AND index_name='idx_audit_correlation'
);
SET @sql := IF(@audit_correlation_index_exists=0,
    'ALTER TABLE system_audit ADD INDEX idx_audit_correlation (correlation_id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO roles (name,description,is_system,organization_id)
SELECT 'employee','Employee self-service: assigned projects, personal time, and permitted personal pay.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name='employee' AND is_system=1);

INSERT INTO role_permissions (role_id,permission,allowed)
SELECT r.id,p.permission,p.allowed
FROM roles r
JOIN (
    SELECT 'timekeeping.self' permission,1 allowed UNION ALL
    SELECT 'profile.view',1 UNION ALL
    SELECT 'profile.edit',1 UNION ALL
    SELECT 'projects.view',0 UNION ALL
    SELECT 'billing.view',0 UNION ALL
    SELECT 'financial.view',0 UNION ALL
    SELECT 'employee_pay.self',1 UNION ALL
    SELECT 'approvals.review',0 UNION ALL
    SELECT 'workforce.manage',0
) p
WHERE r.name='employee' AND r.is_system=1
ON DUPLICATE KEY UPDATE allowed=VALUES(allowed);

INSERT INTO role_permissions (role_id,permission,allowed)
SELECT r.id,p.permission,1
FROM roles r
JOIN (
    SELECT 'timekeeping.self' permission UNION ALL
    SELECT 'timekeeping.manage' UNION ALL
    SELECT 'approvals.review' UNION ALL
    SELECT 'workforce.manage' UNION ALL
    SELECT 'employee_pay.view' UNION ALL
    SELECT 'employee_pay.manage' UNION ALL
    SELECT 'employee_pay.self'
) p
WHERE r.name IN ('admin','owner') AND r.is_system=1
ON DUPLICATE KEY UPDATE allowed=VALUES(allowed);
