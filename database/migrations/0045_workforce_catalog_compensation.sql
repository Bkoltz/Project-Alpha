-- Workforce identities, scoped access, catalog fulfillment, assignment pay,
-- pay periods, and immutable catalog/document compensation snapshots.

INSERT INTO app_config (organization_id,config_key,config_value) VALUES
    (0,'workforce_pay_period_cadence','biweekly'),
    (0,'workforce_pay_period_anchor',''),
    (0,'workforce_pay_period_custom_days','14')
ON DUPLICATE KEY UPDATE config_value=config_value;

CREATE TABLE IF NOT EXISTS business_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    code VARCHAR(32) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_business_unit_code (code),
    INDEX idx_business_unit_active (is_active,name),
    CONSTRAINT fk_business_unit_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    relationship_type VARCHAR(50) NOT NULL DEFAULT 'employee',
    status ENUM('active','inactive','terminated') NOT NULL DEFAULT 'active',
    display_name VARCHAR(255) NOT NULL DEFAULT '',
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    owner_internal_cost_rate DECIMAL(12,4) NULL,
    hired_at DATE NULL,
    ended_at DATE NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_worker_profile_user (user_id),
    INDEX idx_worker_profile_status (status,relationship_type),
    CONSTRAINT fk_worker_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_worker_document_profile := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_documents' AND column_name='worker_profile_id');
SET @sql := IF(@has_worker_document_profile=0, 'ALTER TABLE worker_documents ADD COLUMN worker_profile_id INT NULL AFTER user_id, ADD INDEX idx_worker_documents_profile_status (worker_profile_id,status,created_at), ADD CONSTRAINT fk_worker_document_profile FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS worker_business_units (
    worker_profile_id INT NOT NULL,
    business_unit_id INT NOT NULL,
    is_lead TINYINT(1) NOT NULL DEFAULT 0,
    assigned_by INT NULL,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ends_at DATETIME(6) NULL,
    PRIMARY KEY (worker_profile_id,business_unit_id),
    INDEX idx_worker_unit_active (business_unit_id,ends_at),
    CONSTRAINT fk_worker_unit_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_unit_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_unit_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_business_units (
    client_id INT NOT NULL,
    business_unit_id INT NOT NULL,
    assigned_by INT NULL,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (client_id,business_unit_id),
    INDEX idx_client_unit_unit (business_unit_id,client_id),
    CONSTRAINT fk_client_unit_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_unit_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_unit_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_capability_scopes (
    worker_profile_id INT NOT NULL,
    capability VARCHAR(100) NOT NULL,
    access_scope ENUM('own','assigned','business_unit','all') NOT NULL DEFAULT 'own',
    allowed TINYINT(1) NOT NULL DEFAULT 1,
    granted_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (worker_profile_id,capability),
    INDEX idx_worker_capability_lookup (capability,access_scope,allowed),
    CONSTRAINT fk_worker_capability_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_capability_granter FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    code VARCHAR(64) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    default_compensation_method ENUM('nonpayable','hourly','fixed','base_overage','percentage') NOT NULL DEFAULT 'nonpayable',
    default_amount DECIMAL(12,4) NULL,
    default_base_minutes INT UNSIGNED NULL,
    default_overage_rate DECIMAL(12,4) NULL,
    default_percentage DECIMAL(7,4) NULL,
    default_percentage_basis ENUM('gross_line','net_line','cash_collected') NOT NULL DEFAULT 'net_line',
    default_eligibility_trigger ENUM('completed_approved','delivered','invoice_paid','manual_release') NOT NULL DEFAULT 'completed_approved',
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_work_type_code (code),
    INDEX idx_work_type_active (is_active,name),
    CONSTRAINT fk_work_type_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_item_entry_type := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='item_library' AND column_name='entry_type');
SET @sql := IF(@has_item_entry_type=0, 'ALTER TABLE item_library ADD COLUMN entry_type ENUM(''product'',''service'',''fee'',''bundle'') NOT NULL DEFAULT ''product'' AFTER description, ADD COLUMN billing_unit ENUM(''each'',''hour'',''day'',''mile'',''project'') NOT NULL DEFAULT ''each'' AFTER unit_price, ADD COLUMN tax_behavior ENUM(''inherit'',''taxable'',''exempt'') NOT NULL DEFAULT ''inherit'' AFTER billing_unit, ADD COLUMN fulfillment_notes TEXT NULL AFTER tax_behavior, ADD INDEX idx_item_lib_type_active (entry_type,is_active)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- All document line billing units must be able to snapshot every catalog unit.
ALTER TABLE quote_items MODIFY COLUMN billing_unit ENUM('each','hour','day','mile','project') NOT NULL DEFAULT 'each';
ALTER TABLE contract_items MODIFY COLUMN billing_unit ENUM('each','hour','day','mile','project') NOT NULL DEFAULT 'each';
ALTER TABLE invoice_items MODIFY COLUMN billing_unit ENUM('each','hour','day','mile','project') NOT NULL DEFAULT 'each';

CREATE TABLE IF NOT EXISTS catalog_bundle_items (
    bundle_item_library_id INT NOT NULL,
    child_item_library_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (bundle_item_library_id,child_item_library_id),
    INDEX idx_catalog_bundle_child (child_item_library_id),
    CONSTRAINT fk_catalog_bundle_parent FOREIGN KEY (bundle_item_library_id) REFERENCES item_library(id) ON DELETE CASCADE,
    CONSTRAINT fk_catalog_bundle_child FOREIGN KEY (child_item_library_id) REFERENCES item_library(id) ON DELETE RESTRICT,
    CONSTRAINT chk_catalog_bundle_quantity CHECK (quantity > 0),
    CONSTRAINT chk_catalog_bundle_not_self CHECK (bundle_item_library_id <> child_item_library_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalog_work_components (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_library_id INT NOT NULL,
    work_type_id INT NOT NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    quantity_behavior ENUM('per_line','per_unit','fixed') NOT NULL DEFAULT 'per_line',
    fixed_quantity DECIMAL(10,2) NULL,
    expected_duration_minutes INT UNSIGNED NULL,
    assignment_required TINYINT(1) NOT NULL DEFAULT 1,
    compensation_method ENUM('nonpayable','hourly','fixed','base_overage','percentage') NOT NULL DEFAULT 'nonpayable',
    compensation_amount DECIMAL(12,4) NULL,
    included_minutes INT UNSIGNED NULL,
    overage_rate DECIMAL(12,4) NULL,
    percentage DECIMAL(7,4) NULL,
    percentage_basis ENUM('gross_line','net_line','cash_collected') NOT NULL DEFAULT 'net_line',
    eligibility_trigger ENUM('completed_approved','delivered','invoice_paid','manual_release') NOT NULL DEFAULT 'completed_approved',
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_catalog_component_item (item_library_id,is_active,display_order),
    INDEX idx_catalog_component_work_type (work_type_id),
    CONSTRAINT fk_catalog_component_item FOREIGN KEY (item_library_id) REFERENCES item_library(id) ON DELETE RESTRICT,
    CONSTRAINT fk_catalog_component_work_type FOREIGN KEY (work_type_id) REFERENCES work_types(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_compensation_rules (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    worker_profile_id INT NOT NULL,
    work_type_id INT NULL,
    catalog_work_component_id INT NULL,
    compensation_method ENUM('nonpayable','hourly','fixed','base_overage','percentage') NOT NULL,
    compensation_amount DECIMAL(12,4) NULL,
    included_minutes INT UNSIGNED NULL,
    overage_rate DECIMAL(12,4) NULL,
    percentage DECIMAL(7,4) NULL,
    percentage_basis ENUM('gross_line','net_line','cash_collected') NOT NULL DEFAULT 'net_line',
    eligibility_trigger ENUM('completed_approved','delivered','invoice_paid','manual_release') NOT NULL DEFAULT 'completed_approved',
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    effective_from DATE NOT NULL,
    effective_until DATE NULL,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_worker_rule_component (worker_profile_id,catalog_work_component_id,effective_from),
    INDEX idx_worker_rule_type (worker_profile_id,work_type_id,effective_from),
    CONSTRAINT fk_worker_rule_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_rule_type FOREIGN KEY (work_type_id) REFERENCES work_types(id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_rule_component FOREIGN KEY (catalog_work_component_id) REFERENCES catalog_work_components(id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_rule_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_worker_rule_scope CHECK ((work_type_id IS NOT NULL) <> (catalog_work_component_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Polymorphic document-line references intentionally retain the immutable source
-- even if a document is later voided. Only the catalog entry has a real FK.
SET @has_quote_catalog := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quote_items' AND column_name='item_library_id');
SET @sql := IF(@has_quote_catalog=0, 'ALTER TABLE quote_items ADD COLUMN item_library_id INT NULL AFTER quote_id, ADD COLUMN catalog_snapshot JSON NULL AFTER pricing_status, ADD INDEX idx_quote_item_catalog (item_library_id), ADD CONSTRAINT fk_quote_item_catalog FOREIGN KEY (item_library_id) REFERENCES item_library(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has_contract_catalog := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='contract_items' AND column_name='item_library_id');
SET @sql := IF(@has_contract_catalog=0, 'ALTER TABLE contract_items ADD COLUMN item_library_id INT NULL AFTER contract_id, ADD COLUMN catalog_snapshot JSON NULL AFTER pricing_status, ADD INDEX idx_contract_item_catalog (item_library_id), ADD CONSTRAINT fk_contract_item_catalog FOREIGN KEY (item_library_id) REFERENCES item_library(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has_invoice_catalog := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoice_items' AND column_name='item_library_id');
SET @sql := IF(@has_invoice_catalog=0, 'ALTER TABLE invoice_items ADD COLUMN item_library_id INT NULL AFTER invoice_id, ADD COLUMN catalog_snapshot JSON NULL AFTER pricing_status, ADD INDEX idx_invoice_item_catalog (item_library_id), ADD CONSTRAINT fk_invoice_item_catalog FOREIGN KEY (item_library_id) REFERENCES item_library(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS job_work_components (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    item_library_id INT NULL,
    catalog_work_component_id INT NULL,
    work_type_id INT NOT NULL,
    source_type ENUM('quote','contract','invoice','catalog','manual') NOT NULL,
    source_document_id INT NULL,
    source_line_id INT NULL,
    source_revision INT NULL,
    idempotency_key CHAR(64) NOT NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    planned_quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    expected_duration_minutes INT UNSIGNED NULL,
    assignment_required TINYINT(1) NOT NULL DEFAULT 1,
    compensation_snapshot JSON NOT NULL,
    status ENUM('planned','in_progress','completed','cancelled') NOT NULL DEFAULT 'planned',
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_job_work_idempotency (idempotency_key),
    INDEX idx_job_work_job_status (job_id,status),
    INDEX idx_job_work_source (source_type,source_document_id,source_line_id),
    CONSTRAINT fk_job_work_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_job_work_item FOREIGN KEY (item_library_id) REFERENCES item_library(id) ON DELETE SET NULL,
    CONSTRAINT fk_job_work_catalog_component FOREIGN KEY (catalog_work_component_id) REFERENCES catalog_work_components(id) ON DELETE SET NULL,
    CONSTRAINT fk_job_work_type FOREIGN KEY (work_type_id) REFERENCES work_types(id) ON DELETE RESTRICT,
    CONSTRAINT fk_job_work_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_assignments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_work_component_id BIGINT NOT NULL,
    worker_profile_id INT NULL,
    status ENUM('planned','offered','accepted','declined','in_progress','completed','eligible','approved_payable','settled','cancelled') NOT NULL DEFAULT 'planned',
    compensation_override JSON NULL,
    compensation_snapshot JSON NULL,
    eligibility_snapshot JSON NULL,
    estimated_pay DECIMAL(12,2) NULL,
    approved_pay DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    offered_by INT NULL,
    offered_at DATETIME(6) NULL,
    responded_at DATETIME(6) NULL,
    decline_reason VARCHAR(1000) NULL,
    completed_at DATETIME(6) NULL,
    eligible_at DATETIME(6) NULL,
    eligible_by INT NULL,
    approved_by INT NULL,
    approved_at DATETIME(6) NULL,
    settled_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_assignment_worker_status (worker_profile_id,status,offered_at),
    INDEX idx_assignment_component_status (job_work_component_id,status),
    CONSTRAINT fk_assignment_component FOREIGN KEY (job_work_component_id) REFERENCES job_work_components(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_offerer FOREIGN KEY (offered_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_assignment_eligibility_actor FOREIGN KEY (eligible_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_assignment_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_work_job := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='work_time_entries' AND column_name='job_id');
SET @sql := IF(@has_work_job=0, 'ALTER TABLE work_time_entries ADD COLUMN job_id INT NULL AFTER project_id, ADD COLUMN work_type_id INT NULL AFTER job_id, ADD COLUMN work_assignment_id BIGINT NULL AFTER work_type_id, ADD COLUMN entry_mode ENUM(''timer'',''exact'',''duration'') NOT NULL DEFAULT ''exact'' AFTER work_assignment_id, ADD COLUMN owner_self_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_payable, ADD COLUMN internal_cost_rate DECIMAL(12,4) NULL AFTER owner_self_confirmed, ADD INDEX idx_work_time_job (job_id,status), ADD INDEX idx_work_time_type (work_type_id), ADD INDEX idx_work_time_assignment (work_assignment_id), ADD CONSTRAINT fk_work_time_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL, ADD CONSTRAINT fk_work_time_type FOREIGN KEY (work_type_id) REFERENCES work_types(id) ON DELETE SET NULL, ADD CONSTRAINT fk_work_time_assignment FOREIGN KEY (work_assignment_id) REFERENCES work_assignments(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS pay_periods (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    cadence ENUM('weekly','biweekly','semimonthly','monthly','custom') NOT NULL DEFAULT 'biweekly',
    status ENUM('open','closing','closed') NOT NULL DEFAULT 'open',
    closed_by INT NULL,
    closed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_pay_period_dates (period_start,period_end),
    INDEX idx_pay_period_status (status,period_start),
    CONSTRAINT fk_pay_period_closer FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_pay_period_dates CHECK (period_end >= period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_period_submissions (
    pay_period_id BIGINT NOT NULL,
    worker_profile_id INT NOT NULL,
    status ENUM('not_submitted','submitted','accepted','adjusted') NOT NULL DEFAULT 'not_submitted',
    submitted_at DATETIME(6) NULL,
    accepted_by INT NULL,
    accepted_at DATETIME(6) NULL,
    notes TEXT NULL,
    PRIMARY KEY (pay_period_id,worker_profile_id),
    CONSTRAINT fk_period_submission_period FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_period_submission_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_period_submission_acceptor FOREIGN KEY (accepted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_statements (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    pay_period_id BIGINT NOT NULL,
    worker_profile_id INT NOT NULL,
    statement_type ENUM('employee_pay','contractor_settlement') NOT NULL,
    status ENUM('draft','issued','settled','voided') NOT NULL DEFAULT 'draft',
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    adjustment_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    contractor_invoice_path VARCHAR(500) NULL,
    contractor_invoice_sha256 CHAR(64) NULL,
    issued_at DATETIME(6) NULL,
    settled_at DATETIME(6) NULL,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_worker_statement_period (pay_period_id,worker_profile_id),
    INDEX idx_worker_statement_status (worker_profile_id,status),
    CONSTRAINT fk_worker_statement_period FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_statement_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_statement_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_statement_lines (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    worker_statement_id BIGINT NOT NULL,
    work_assignment_id BIGINT NULL,
    work_time_entry_id CHAR(36) NULL,
    description VARCHAR(500) NOT NULL,
    quantity DECIMAL(12,4) NOT NULL DEFAULT 1,
    rate DECIMAL(12,4) NULL,
    amount DECIMAL(12,2) NOT NULL,
    calculation_snapshot JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_statement_assignment (worker_statement_id,work_assignment_id),
    UNIQUE KEY uq_statement_time_entry (worker_statement_id,work_time_entry_id),
    INDEX idx_statement_line_time (work_time_entry_id),
    CONSTRAINT fk_statement_line_statement FOREIGN KEY (worker_statement_id) REFERENCES worker_statements(id) ON DELETE CASCADE,
    CONSTRAINT fk_statement_line_assignment FOREIGN KEY (work_assignment_id) REFERENCES work_assignments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_statement_line_time FOREIGN KEY (work_time_entry_id) REFERENCES work_time_entries(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compensation_adjustments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    worker_profile_id INT NOT NULL,
    pay_period_id BIGINT NOT NULL,
    source_assignment_id BIGINT NULL,
    adjustment_type ENUM('credit','debit') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    source_snapshot JSON NULL,
    status ENUM('pending','reviewed','applied','voided') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at DATETIME(6) NULL,
    statement_line_id BIGINT NULL,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_comp_adjustment_period (pay_period_id,worker_profile_id,status),
    CONSTRAINT fk_comp_adjustment_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_comp_adjustment_period FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id) ON DELETE RESTRICT,
    CONSTRAINT fk_comp_adjustment_assignment FOREIGN KEY (source_assignment_id) REFERENCES work_assignments(id) ON DELETE SET NULL,
    CONSTRAINT fk_comp_adjustment_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_comp_adjustment_line FOREIGN KEY (statement_line_id) REFERENCES worker_statement_lines(id) ON DELETE SET NULL,
    CONSTRAINT fk_comp_adjustment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_mileage_traveler_worker := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='mileage_logs' AND column_name='traveler_worker_id');
SET @sql := IF(@has_mileage_traveler_worker=0, 'ALTER TABLE mileage_logs ADD COLUMN traveler_worker_id INT NULL AFTER user_id, ADD COLUMN financial_treatment ENUM(''organization_mileage'',''worker_reimbursement'',''contractor_record_only'',''nonreimbursable'') NOT NULL DEFAULT ''organization_mileage'' AFTER traveler_worker_id, ADD INDEX idx_mileage_worker_treatment (traveler_worker_id,financial_treatment,trip_date), ADD CONSTRAINT fk_mileage_traveler_worker FOREIGN KEY (traveler_worker_id) REFERENCES worker_profiles(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Existing employee profiles and business owners become worker identities. Other
-- application users are not silently treated as paid workers.
INSERT INTO worker_profiles (user_id,relationship_type,status,display_name,currency,hired_at,ended_at)
SELECT u.id,
       CASE WHEN u.role IN ('admin','owner') THEN 'owner' ELSE 'employee' END,
       COALESCE(ep.employment_status,CASE WHEN u.is_disabled=1 OR u.deleted_at IS NOT NULL THEN 'inactive' ELSE 'active' END),
       COALESCE(NULLIF(TRIM(CONCAT_WS(' ',ep.first_name,ep.last_name)),''),NULLIF(u.username,''),u.email),
       COALESCE(ep.currency,'USD'),ep.hired_at,ep.terminated_at
FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id
WHERE ep.user_id IS NOT NULL OR u.role IN ('admin','owner')
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),status=VALUES(status);

UPDATE worker_documents d
JOIN worker_profiles wp ON wp.user_id=d.user_id
SET d.worker_profile_id=wp.id
WHERE d.worker_profile_id IS NULL;

UPDATE item_library
SET entry_type='service',billing_unit='hour'
WHERE category='Hourly';

UPDATE mileage_logs m
JOIN worker_profiles wp ON wp.user_id=m.user_id
SET m.traveler_worker_id=wp.id,
    m.financial_treatment=CASE
        WHEN wp.relationship_type='contractor' THEN 'contractor_record_only'
        WHEN wp.relationship_type='employee' THEN 'worker_reimbursement'
        ELSE 'organization_mileage'
    END
WHERE m.traveler_worker_id IS NULL;

INSERT INTO role_permissions (role_id,permission,allowed)
SELECT r.id,p.permission,1
FROM roles r JOIN (
    SELECT 'workforce.catalog.manage' permission UNION ALL
    SELECT 'workforce.assignments.manage' UNION ALL
    SELECT 'workforce.pay_periods.manage' UNION ALL
    SELECT 'workforce.statements.manage' UNION ALL
    SELECT 'workforce.business_units.manage'
) p
WHERE r.name IN ('admin','owner') AND r.is_system=1
ON DUPLICATE KEY UPDATE allowed=VALUES(allowed);

INSERT INTO role_permissions (role_id,permission,allowed)
SELECT r.id,p.permission,1
FROM roles r JOIN (
    SELECT 'workforce.assignments.self' permission UNION ALL
    SELECT 'workforce.statements.self' UNION ALL
    SELECT 'workforce.directory.search'
) p
WHERE r.name='employee' AND r.is_system=1
ON DUPLICATE KEY UPDATE allowed=VALUES(allowed);
