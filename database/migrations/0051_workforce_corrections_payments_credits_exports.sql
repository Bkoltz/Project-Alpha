-- Append-only workforce corrections, worker payments, payroll exports, and client credits.

SET @has_statement_version := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_statements' AND column_name='statement_version');
SET @sql := IF(@has_statement_version=0, 'ALTER TABLE worker_statements DROP INDEX uq_worker_statement_period, ADD COLUMN statement_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER worker_profile_id, ADD COLUMN replaces_statement_id BIGINT NULL AFTER statement_version, ADD COLUMN voided_by INT NULL AFTER settled_at, ADD COLUMN voided_at DATETIME(6) NULL AFTER voided_by, ADD COLUMN void_reason VARCHAR(1000) NULL AFTER voided_at, ADD UNIQUE KEY uq_worker_statement_version (pay_period_id,worker_profile_id,statement_version), ADD INDEX idx_worker_statement_replacement (replaces_statement_id), ADD CONSTRAINT fk_worker_statement_replacement FOREIGN KEY (replaces_statement_id) REFERENCES worker_statements(id) ON DELETE SET NULL, ADD CONSTRAINT fk_worker_statement_voider FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_invoice_credit_applied := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='credit_applied');
SET @sql := IF(@has_invoice_credit_applied=0, 'ALTER TABLE invoices ADD COLUMN credit_applied DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount_paid', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @invoice_status_supports_credit := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='status' AND column_type LIKE '%credited%');
SET @sql := IF(@invoice_status_supports_credit=0, 'ALTER TABLE invoices MODIFY COLUMN status ENUM(''draft'',''sent'',''unpaid'',''partial'',''paid'',''credited'',''overdue'',''cancelled'',''void'') NOT NULL DEFAULT ''draft''', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS time_correction_requests (
    id CHAR(36) NOT NULL PRIMARY KEY,
    time_entry_id CHAR(36) NOT NULL,
    original_revision INT UNSIGNED NOT NULL,
    original_snapshot JSON NOT NULL,
    proposed_snapshot JSON NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    requested_by INT NOT NULL,
    resolved_by INT NULL,
    resolved_at DATETIME(6) NULL,
    resolution_notes VARCHAR(1000) NULL,
    applied_revision INT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_time_correction_queue (status,created_at),
    INDEX idx_time_correction_entry (time_entry_id,original_revision),
    CONSTRAINT fk_time_correction_entry FOREIGN KEY (time_entry_id) REFERENCES work_time_entries(id) ON DELETE RESTRICT,
    CONSTRAINT fk_time_correction_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_time_correction_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_correction_effects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    correction_request_id CHAR(36) NOT NULL,
    original_worker_earning_id CHAR(36) NULL,
    compensation_adjustment_id BIGINT NULL,
    original_statement_id BIGINT NULL,
    replacement_statement_id BIGINT NULL,
    duration_delta_seconds INT NOT NULL DEFAULT 0,
    worker_amount_delta DECIMAL(12,2) NOT NULL DEFAULT 0,
    billing_amount_delta DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    statement_action ENUM('none','draft_rebuild','void_reissue','next_period_adjustment') NOT NULL DEFAULT 'none',
    billing_action ENUM('none','draft_update','admin_review') NOT NULL DEFAULT 'none',
    effect_snapshot JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_time_correction_effect (correction_request_id),
    INDEX idx_time_correction_effect_statement (original_statement_id,replacement_statement_id),
    CONSTRAINT fk_time_correction_effect_request FOREIGN KEY (correction_request_id) REFERENCES time_correction_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_time_correction_effect_earning FOREIGN KEY (original_worker_earning_id) REFERENCES worker_earnings(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_correction_effect_adjustment FOREIGN KEY (compensation_adjustment_id) REFERENCES compensation_adjustments(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_correction_effect_original_statement FOREIGN KEY (original_statement_id) REFERENCES worker_statements(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_correction_effect_replacement_statement FOREIGN KEY (replacement_statement_id) REFERENCES worker_statements(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_payment_records (
    id CHAR(36) NOT NULL PRIMARY KEY,
    worker_profile_id INT NOT NULL,
    record_source ENUM('admin_confirmed','legacy_statement_backfill') NOT NULL DEFAULT 'admin_confirmed',
    legacy_statement_id BIGINT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    payment_method VARCHAR(50) NOT NULL,
    reference_number VARCHAR(255) NULL,
    notes VARCHAR(1000) NULL,
    status ENUM('confirmed','voided') NOT NULL DEFAULT 'confirmed',
    created_by INT NULL,
    voided_by INT NULL,
    voided_at DATETIME(6) NULL,
    void_reason VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_worker_payment_legacy_statement (legacy_statement_id),
    INDEX idx_worker_payment_worker (worker_profile_id,payment_date,status),
    CONSTRAINT fk_worker_payment_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_payment_legacy_statement FOREIGN KEY (legacy_statement_id) REFERENCES worker_statements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_payment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_worker_payment_voider FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_worker_payment_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_payment_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_payment_record_id CHAR(36) NOT NULL,
    worker_statement_id BIGINT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_worker_payment_statement (worker_payment_record_id,worker_statement_id),
    INDEX idx_worker_payment_allocation_statement (worker_statement_id),
    CONSTRAINT fk_worker_payment_allocation_record FOREIGN KEY (worker_payment_record_id) REFERENCES worker_payment_records(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_payment_allocation_statement FOREIGN KEY (worker_statement_id) REFERENCES worker_statements(id) ON DELETE RESTRICT,
    CONSTRAINT chk_worker_payment_allocation_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO worker_payment_records
    (id,worker_profile_id,record_source,legacy_statement_id,payment_date,amount,currency,payment_method,reference_number,notes,status,created_by)
SELECT LOWER(CONCAT(
           SUBSTRING(SHA2(CONCAT('legacy-worker-statement:',s.id),256),1,8),'-',
           SUBSTRING(SHA2(CONCAT('legacy-worker-statement:',s.id),256),9,4),'-',
           SUBSTRING(SHA2(CONCAT('legacy-worker-statement:',s.id),256),13,4),'-',
           SUBSTRING(SHA2(CONCAT('legacy-worker-statement:',s.id),256),17,4),'-',
           SUBSTRING(SHA2(CONCAT('legacy-worker-statement:',s.id),256),21,12))),
       s.worker_profile_id,'legacy_statement_backfill',s.id,
       DATE(COALESCE(s.settled_at,s.issued_at,s.updated_at,s.created_at)),
       s.total_amount,s.currency,'legacy',CONCAT('Legacy statement #',s.id),
       'Backfilled from a statement already marked settled before Worker Payment Records were introduced.',
       'confirmed',s.created_by
FROM worker_statements s
WHERE s.status='settled' AND s.total_amount>0;

INSERT IGNORE INTO worker_payment_allocations (worker_payment_record_id,worker_statement_id,amount)
SELECT p.id,s.id,s.total_amount
FROM worker_statements s
JOIN worker_payment_records p ON p.legacy_statement_id=s.id AND p.record_source='legacy_statement_backfill'
WHERE s.status='settled' AND s.total_amount>0;

CREATE TABLE IF NOT EXISTS payroll_exports (
    id CHAR(36) NOT NULL PRIMARY KEY,
    export_key VARCHAR(190) NOT NULL,
    pay_period_id BIGINT NULL,
    status ENUM('generated','voided') NOT NULL DEFAULT 'generated',
    format VARCHAR(20) NOT NULL DEFAULT 'csv',
    file_name VARCHAR(255) NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    csv_content MEDIUMTEXT NOT NULL,
    row_count INT UNSIGNED NOT NULL,
    gross_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NULL,
    created_by INT NOT NULL,
    voided_by INT NULL,
    voided_at DATETIME(6) NULL,
    void_reason VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_payroll_export_key (export_key),
    INDEX idx_payroll_export_period (pay_period_id,status,created_at),
    CONSTRAINT fk_payroll_export_period FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id) ON DELETE SET NULL,
    CONSTRAINT fk_payroll_export_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payroll_export_voider FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_export_rows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_export_id CHAR(36) NOT NULL,
    worker_earning_id CHAR(36) NOT NULL,
    export_row_number INT UNSIGNED NOT NULL,
    signed_amount DECIMAL(12,2) NOT NULL,
    row_snapshot JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_payroll_export_row_number (payroll_export_id,export_row_number),
    UNIQUE KEY uq_payroll_export_earning (payroll_export_id,worker_earning_id),
    CONSTRAINT fk_payroll_export_row_export FOREIGN KEY (payroll_export_id) REFERENCES payroll_exports(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payroll_export_row_earning FOREIGN KEY (worker_earning_id) REFERENCES worker_earnings(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_credits (
    id CHAR(36) NOT NULL PRIMARY KEY,
    client_id INT NOT NULL,
    organization_id INT NULL,
    source_invoice_id INT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    original_amount DECIMAL(12,2) NOT NULL,
    remaining_amount DECIMAL(12,2) NOT NULL,
    status ENUM('available','partially_applied','applied','refunded','voided') NOT NULL DEFAULT 'available',
    reason VARCHAR(1000) NOT NULL,
    created_by INT NOT NULL,
    voided_by INT NULL,
    voided_at DATETIME(6) NULL,
    void_reason VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_client_credit_balance (client_id,organization_id,currency,status),
    CONSTRAINT fk_client_credit_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_client_credit_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_credit_invoice FOREIGN KEY (source_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_credit_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_client_credit_voider FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_client_credit_amount CHECK (original_amount > 0 AND remaining_amount >= 0 AND remaining_amount <= original_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_credit_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_credit_id CHAR(36) NOT NULL,
    event_type ENUM('issued','allocated','allocation_reversed','refund_recorded','voided') NOT NULL,
    invoice_id INT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_id INT NULL,
    reference_number VARCHAR(255) NULL,
    reason VARCHAR(1000) NULL,
    event_snapshot JSON NOT NULL,
    actor_id INT NOT NULL,
    reverses_event_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_client_credit_event (client_credit_id,created_at),
    INDEX idx_client_credit_invoice (invoice_id),
    CONSTRAINT fk_client_credit_event_credit FOREIGN KEY (client_credit_id) REFERENCES client_credits(id) ON DELETE RESTRICT,
    CONSTRAINT fk_client_credit_event_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_credit_event_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_credit_event_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_client_credit_event_reversal FOREIGN KEY (reverses_event_id) REFERENCES client_credit_events(id) ON DELETE SET NULL,
    CONSTRAINT chk_client_credit_event_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_correction_billing_resolutions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    correction_effect_id BIGINT UNSIGNED NOT NULL,
    decision ENUM('invoice_adjustment','move_to_draft','absorb') NOT NULL,
    source_invoice_id INT NULL,
    target_invoice_id INT NULL,
    invoice_adjustment_id BIGINT NULL,
    client_credit_id CHAR(36) NULL,
    signed_amount DECIMAL(12,2) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    actor_id INT NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_time_correction_billing_resolution (correction_effect_id),
    CONSTRAINT fk_time_correction_resolution_effect FOREIGN KEY (correction_effect_id) REFERENCES time_correction_effects(id) ON DELETE RESTRICT,
    CONSTRAINT fk_time_correction_resolution_source_invoice FOREIGN KEY (source_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_correction_resolution_target_invoice FOREIGN KEY (target_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_correction_resolution_adjustment FOREIGN KEY (invoice_adjustment_id) REFERENCES invoice_adjustments(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_correction_resolution_credit FOREIGN KEY (client_credit_id) REFERENCES client_credits(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_correction_resolution_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO role_permissions (role_id,permission,allowed)
SELECT r.id,p.permission,1
FROM roles r
JOIN (
    SELECT 'workforce.corrections.manage' permission UNION ALL
    SELECT 'workforce.payments.manage' UNION ALL
    SELECT 'workforce.payroll_exports.manage' UNION ALL
    SELECT 'billing.client_credits.manage'
) p
WHERE r.name IN ('owner','admin')
ON DUPLICATE KEY UPDATE allowed=VALUES(allowed);
