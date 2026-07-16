-- Canonical Workforce workflow foundation. Existing status, pay-accrual, and
-- billing-projection tables remain readable while new services migrate to
-- submissions, billing allocations, and unified worker earnings.

INSERT INTO app_config (organization_id,config_key,config_value) VALUES
    (0,'workforce_default_capture_mode','duration'),
    (0,'workforce_default_billing_treatment','undecided'),
    (0,'workforce_require_work_type','0'),
    (0,'workforce_require_assignment','0'),
    (0,'workforce_submission_reminders','1')
ON DUPLICATE KEY UPDATE config_value=config_value;

-- A legacy 0045 backfill inferred owner status from the admin account role.
-- Preserve those records, but make the ambiguity visible for reconciliation.
SET @has_relationship_review := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_profiles' AND column_name='relationship_review_required');
SET @sql := IF(@has_relationship_review=0, 'ALTER TABLE worker_profiles ADD COLUMN relationship_review_required TINYINT(1) NOT NULL DEFAULT 0 AFTER relationship_type', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_relationship_reason := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_profiles' AND column_name='relationship_review_reason');
SET @sql := IF(@has_relationship_reason=0, 'ALTER TABLE worker_profiles ADD COLUMN relationship_review_reason VARCHAR(255) NULL AFTER relationship_review_required', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_relationship_reviewer := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_profiles' AND column_name='relationship_reviewed_by');
SET @sql := IF(@has_relationship_reviewer=0, 'ALTER TABLE worker_profiles ADD COLUMN relationship_reviewed_by INT NULL AFTER relationship_review_reason, ADD CONSTRAINT fk_worker_relationship_reviewer FOREIGN KEY (relationship_reviewed_by) REFERENCES users(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_relationship_reviewed_at := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_profiles' AND column_name='relationship_reviewed_at');
SET @sql := IF(@has_relationship_reviewed_at=0, 'ALTER TABLE worker_profiles ADD COLUMN relationship_reviewed_at DATETIME(6) NULL AFTER relationship_reviewed_by', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_time_review_policy := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_profiles' AND column_name='time_review_policy');
SET @sql := IF(@has_time_review_policy=0, 'ALTER TABLE worker_profiles ADD COLUMN time_review_policy ENUM(''manager_review'',''self_confirm'',''auto_confirm'') NOT NULL DEFAULT ''manager_review'' AFTER relationship_reviewed_at', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_compensation_policy := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_profiles' AND column_name='compensation_policy');
SET @sql := IF(@has_compensation_policy=0, 'ALTER TABLE worker_profiles ADD COLUMN compensation_policy ENUM(''rules'',''nonpayable'',''owner_no_pay'',''needs_setup'',''needs_review'') NOT NULL DEFAULT ''rules'' AFTER time_review_policy', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_relationship_review_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='worker_profiles' AND index_name='idx_worker_relationship_review');
SET @sql := IF(@has_relationship_review_index=0, 'ALTER TABLE worker_profiles ADD INDEX idx_worker_relationship_review (relationship_review_required,status)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE worker_profiles wp
JOIN users u ON u.id=wp.user_id
SET wp.relationship_review_required=1,
    wp.relationship_review_reason='legacy_admin_owner_inference',
    wp.time_review_policy='manager_review',
    wp.compensation_policy='needs_review'
WHERE u.role='admin' AND wp.relationship_type='owner'
  AND wp.relationship_reviewed_at IS NULL;

UPDATE worker_profiles wp
LEFT JOIN users u ON u.id=wp.user_id
SET wp.time_review_policy='self_confirm',wp.compensation_policy='owner_no_pay'
WHERE wp.relationship_type='owner'
  AND wp.relationship_review_required=0
  AND (u.id IS NULL OR u.role<>'admin');

-- Canonical identity and lifecycle fields coexist with legacy user_id/status.
SET @has_entry_worker := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='work_time_entries' AND column_name='worker_profile_id');
SET @sql := IF(@has_entry_worker=0, 'ALTER TABLE work_time_entries ADD COLUMN worker_profile_id INT NULL AFTER user_id, ADD INDEX idx_work_time_worker_status (worker_profile_id,status,start_time), ADD CONSTRAINT fk_work_time_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE RESTRICT', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_entry_recorder := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='work_time_entries' AND column_name='entered_by_user_id');
SET @sql := IF(@has_entry_recorder=0, 'ALTER TABLE work_time_entries ADD COLUMN entered_by_user_id INT NULL AFTER worker_profile_id, ADD INDEX idx_work_time_recorder (entered_by_user_id,created_at), ADD CONSTRAINT fk_work_time_recorder FOREIGN KEY (entered_by_user_id) REFERENCES users(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_workflow_status := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='work_time_entries' AND column_name='workflow_status');
SET @sql := IF(@has_workflow_status=0, 'ALTER TABLE work_time_entries ADD COLUMN workflow_status ENUM(''running'',''draft'',''submitted'',''returned'',''confirmed'',''voided'') NOT NULL DEFAULT ''draft'' AFTER status, ADD INDEX idx_work_time_workflow (workflow_status,start_time,worker_profile_id)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_billing_state := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='work_time_entries' AND column_name='billing_state');
SET @sql := IF(@has_billing_state=0, 'ALTER TABLE work_time_entries ADD COLUMN billing_state ENUM(''decide_later'',''internal'',''fixed_price_included'',''rate_needed'',''ready'',''partially_invoiced'',''invoiced'',''reversed'') NOT NULL DEFAULT ''decide_later'' AFTER workflow_status, ADD INDEX idx_work_time_billing_state (billing_state,workflow_status)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_compensation_state := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='work_time_entries' AND column_name='compensation_state');
SET @sql := IF(@has_compensation_state=0, 'ALTER TABLE work_time_entries ADD COLUMN compensation_state ENUM(''owner_no_pay'',''nonpayable'',''needs_setup'',''provisional'',''eligible'',''approved'',''included'',''settled'',''adjusted'',''voided'') NOT NULL DEFAULT ''provisional'' AFTER billing_state, ADD INDEX idx_work_time_compensation_state (compensation_state,workflow_status)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_submitted_at := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='work_time_entries' AND column_name='submitted_at');
SET @sql := IF(@has_submitted_at=0, 'ALTER TABLE work_time_entries ADD COLUMN submitted_at DATETIME(6) NULL AFTER rejection_reason', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE work_time_entries t
LEFT JOIN worker_profiles wp ON wp.user_id=t.user_id
SET t.worker_profile_id=COALESCE(t.worker_profile_id,wp.id),
    t.entered_by_user_id=COALESCE(t.entered_by_user_id,t.user_id),
    t.workflow_status=CASE t.status
        WHEN 'running' THEN 'running'
        WHEN 'review' THEN 'submitted'
        WHEN 'rejected' THEN 'returned'
        WHEN 'approved' THEN 'confirmed'
        ELSE 'voided'
    END,
    t.billing_state=CASE
        WHEN t.invoice_id IS NOT NULL THEN 'invoiced'
        WHEN t.billable=1 THEN 'rate_needed'
        ELSE 'internal'
    END,
    t.compensation_state=CASE
        WHEN t.status IN ('voided','cancelled') THEN 'voided'
        WHEN wp.relationship_type='owner' AND wp.relationship_review_required=0 THEN 'owner_no_pay'
        WHEN wp.compensation_policy IN ('needs_review','needs_setup') THEN 'needs_setup'
        WHEN t.is_payable=0 THEN 'nonpayable'
        WHEN t.status='approved' THEN 'approved'
        ELSE 'provisional'
    END,
    t.submitted_at=CASE WHEN t.status='review' THEN COALESCE(t.submitted_at,t.updated_at,t.created_at) ELSE t.submitted_at END;

CREATE TABLE IF NOT EXISTS time_submissions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    pay_period_id BIGINT NOT NULL,
    worker_profile_id INT NOT NULL,
    submission_sequence INT UNSIGNED NOT NULL,
    status ENUM('submitted','partially_reviewed','returned','confirmed','voided') NOT NULL DEFAULT 'submitted',
    source ENUM('workflow','legacy_backfill') NOT NULL DEFAULT 'workflow',
    legacy_submission_key VARCHAR(190) NULL,
    notes TEXT NULL,
    submitted_by INT NULL,
    submitted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    reviewed_by INT NULL,
    reviewed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_time_submission_sequence (pay_period_id,worker_profile_id,submission_sequence),
    UNIQUE KEY uq_time_submission_legacy (legacy_submission_key),
    INDEX idx_time_submission_review (status,submitted_at,worker_profile_id),
    CONSTRAINT fk_time_submission_period FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id) ON DELETE RESTRICT,
    CONSTRAINT fk_time_submission_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_time_submission_submitter FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_submission_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_submission_entries (
    submission_id CHAR(36) NOT NULL,
    time_entry_id CHAR(36) NOT NULL,
    entry_revision INT UNSIGNED NOT NULL,
    entry_snapshot JSON NOT NULL,
    decision ENUM('pending','confirmed','returned','voided') NOT NULL DEFAULT 'pending',
    decision_reason VARCHAR(1000) NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (submission_id,time_entry_id),
    INDEX idx_submission_entry_time (time_entry_id,entry_revision),
    INDEX idx_submission_entry_decision (decision,submission_id),
    CONSTRAINT fk_submission_entry_submission FOREIGN KEY (submission_id) REFERENCES time_submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_submission_entry_time FOREIGN KEY (time_entry_id) REFERENCES work_time_entries(id) ON DELETE RESTRICT,
    CONSTRAINT fk_submission_entry_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_current_submission := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='work_time_entries' AND column_name='current_submission_id');
SET @sql := IF(@has_current_submission=0, 'ALTER TABLE work_time_entries ADD COLUMN current_submission_id CHAR(36) NULL AFTER submitted_at, ADD INDEX idx_work_time_submission (current_submission_id), ADD CONSTRAINT fk_work_time_current_submission FOREIGN KEY (current_submission_id) REFERENCES time_submissions(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Retain legacy period markers while creating a revision-level snapshot for
-- every historical submitted/accepted period that can be reconstructed.
INSERT INTO time_submissions
    (id,pay_period_id,worker_profile_id,submission_sequence,status,source,legacy_submission_key,notes,submitted_at,reviewed_by,reviewed_at)
SELECT UUID(),s.pay_period_id,s.worker_profile_id,1,
       CASE WHEN s.status='accepted' THEN 'confirmed' WHEN s.status='adjusted' THEN 'partially_reviewed' ELSE 'submitted' END,
       'legacy_backfill',CONCAT('legacy-period:',s.pay_period_id,':worker:',s.worker_profile_id),s.notes,
       COALESCE(s.submitted_at,UTC_TIMESTAMP(6)),s.accepted_by,s.accepted_at
FROM worker_period_submissions s
WHERE s.status IN ('submitted','accepted','adjusted')
ON DUPLICATE KEY UPDATE id=time_submissions.id;

INSERT INTO time_submission_entries
    (submission_id,time_entry_id,entry_revision,entry_snapshot,decision,reviewed_by,reviewed_at)
SELECT s.id,t.id,t.revision,
       JSON_OBJECT('id',t.id,'revision',t.revision,'worker_profile_id',t.worker_profile_id,
                   'start_time',t.start_time,'end_time',t.end_time,'duration_seconds',t.duration_seconds,
                   'description',t.description,'client_id',t.client_id,'project_id',t.project_id,
                   'job_id',t.job_id,'work_type_id',t.work_type_id,'work_assignment_id',t.work_assignment_id,
                   'billable',t.billable,'is_payable',t.is_payable,'legacy_status',t.status,
                   'workflow_status',t.workflow_status),
       CASE WHEN s.status='confirmed' AND t.workflow_status='confirmed' THEN 'confirmed' ELSE 'pending' END,
       s.reviewed_by,s.reviewed_at
FROM time_submissions s
JOIN pay_periods p ON p.id=s.pay_period_id
JOIN work_time_entries t ON t.worker_profile_id=s.worker_profile_id AND DATE(t.start_time) BETWEEN p.period_start AND p.period_end
WHERE s.source='legacy_backfill'
ON DUPLICATE KEY UPDATE entry_revision=time_submission_entries.entry_revision;

UPDATE work_time_entries t
JOIN time_submission_entries se ON se.time_entry_id=t.id AND se.entry_revision=t.revision
SET t.current_submission_id=COALESCE(t.current_submission_id,se.submission_id)
WHERE t.current_submission_id IS NULL;

-- Work Type billing defaults intentionally live outside work_types, whose
-- existing default_* fields describe worker compensation.
CREATE TABLE IF NOT EXISTS work_type_billing_defaults (
    work_type_id INT NOT NULL PRIMARY KEY,
    default_treatment ENUM('undecided','internal','fixed_price_included','hourly') NOT NULL DEFAULT 'undecided',
    default_billing_rate DECIMAL(12,4) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_work_type_billing_default_type FOREIGN KEY (work_type_id) REFERENCES work_types(id) ON DELETE CASCADE,
    CONSTRAINT fk_work_type_billing_default_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_work_type_billing_default_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_work_type_default_billing_rate CHECK (default_billing_rate IS NULL OR default_billing_rate >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_time_billing_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    allocation_key CHAR(64) NOT NULL,
    time_entry_id CHAR(36) NOT NULL,
    entry_revision INT UNSIGNED NOT NULL,
    treatment ENUM('undecided','internal','fixed_price_included','hourly') NOT NULL DEFAULT 'undecided',
    status ENUM('pending','rate_needed','ready','invoiced','reversed') NOT NULL DEFAULT 'pending',
    duration_seconds INT UNSIGNED NOT NULL,
    quantity DECIMAL(12,4) NOT NULL,
    rate DECIMAL(12,4) NULL,
    amount DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    client_id INT NULL,
    project_id INT NULL,
    job_id INT NULL,
    invoice_id INT NULL,
    invoice_item_id INT NULL,
    allocation_snapshot JSON NOT NULL,
    created_by INT NULL,
    reversed_by INT NULL,
    reversed_at DATETIME(6) NULL,
    reversal_reason VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_time_billing_allocation_key (allocation_key),
    INDEX idx_time_billing_entry (time_entry_id,entry_revision,status),
    INDEX idx_time_billing_queue (status,treatment,created_at),
    INDEX idx_time_billing_invoice (invoice_id,invoice_item_id),
    CONSTRAINT fk_time_billing_entry FOREIGN KEY (time_entry_id) REFERENCES work_time_entries(id) ON DELETE RESTRICT,
    CONSTRAINT fk_time_billing_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_billing_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_billing_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_billing_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_billing_invoice_item FOREIGN KEY (invoice_item_id) REFERENCES invoice_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_billing_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_time_billing_reverser FOREIGN KEY (reversed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_time_billing_quantity CHECK (quantity >= 0),
    CONSTRAINT chk_time_billing_amount CHECK (amount IS NULL OR amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing billing projections are represented additively; their source rows
-- and invoice relationships remain unchanged.
INSERT INTO work_time_billing_allocations
    (allocation_key,time_entry_id,entry_revision,treatment,status,duration_seconds,quantity,rate,amount,currency,client_id,project_id,job_id,invoice_id,invoice_item_id,allocation_snapshot,created_at)
SELECT SHA2(CONCAT('legacy-billing:',c.id),256),s.time_entry_id,s.entry_revision,'hourly',
       CASE WHEN te.invoice_id IS NOT NULL OR te.invoice_item_id IS NOT NULL THEN 'invoiced' WHEN COALESCE(te.rate,s.billing_rate,0)>0 THEN 'ready' ELSE 'rate_needed' END,
       s.duration_seconds,ROUND(s.duration_seconds/3600,4),COALESCE(te.rate,s.billing_rate),
       CASE WHEN COALESCE(te.rate,s.billing_rate) IS NULL THEN NULL ELSE ROUND((s.duration_seconds/3600)*COALESCE(te.rate,s.billing_rate),2) END,
       s.currency,COALESCE(te.client_id,s.client_id),COALESCE(te.project_id,s.project_id),w.job_id,te.invoice_id,te.invoice_item_id,
       JSON_OBJECT('legacy_consumption_id',c.id,'approval_snapshot_id',s.id,'billing_time_entry_id',te.id,'consumption_type',c.consumption_type),c.created_at
FROM work_billing_consumptions c
JOIN work_approval_snapshots s ON s.id=c.approval_snapshot_id
JOIN work_time_entries w ON w.id=s.time_entry_id
JOIN time_entries te ON te.id=c.billing_time_entry_id
WHERE c.consumption_type IN ('approved','correction')
ON DUPLICATE KEY UPDATE id=work_time_billing_allocations.id;

CREATE TABLE IF NOT EXISTS worker_earnings (
    id CHAR(36) NOT NULL PRIMARY KEY,
    source_key VARCHAR(190) NOT NULL,
    source_type ENUM('time_entry','work_assignment','adjustment','mileage','manual','legacy') NOT NULL,
    source_id VARCHAR(64) NOT NULL,
    source_revision INT UNSIGNED NOT NULL DEFAULT 1,
    worker_profile_id INT NOT NULL,
    work_time_entry_id CHAR(36) NULL,
    work_assignment_id BIGINT NULL,
    pay_period_id BIGINT NULL,
    status ENUM('provisional','needs_setup','eligible','approved','included','settled','adjusted','voided') NOT NULL DEFAULT 'provisional',
    method ENUM('hourly','fixed','base_overage','percentage','reimbursement','adjustment','manual') NOT NULL,
    quantity DECIMAL(12,4) NOT NULL DEFAULT 1,
    rate DECIMAL(12,4) NULL,
    amount DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    calculation_snapshot JSON NOT NULL,
    eligible_by INT NULL,
    eligible_at DATETIME(6) NULL,
    approved_by INT NULL,
    approved_at DATETIME(6) NULL,
    statement_line_id BIGINT NULL,
    settled_at DATETIME(6) NULL,
    voided_by INT NULL,
    voided_at DATETIME(6) NULL,
    void_reason VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_worker_earning_source (source_key),
    UNIQUE KEY uq_worker_earning_statement_line (statement_line_id),
    INDEX idx_worker_earning_queue (worker_profile_id,status,created_at),
    INDEX idx_worker_earning_period (pay_period_id,status,worker_profile_id),
    INDEX idx_worker_earning_time (work_time_entry_id,source_revision),
    INDEX idx_worker_earning_assignment (work_assignment_id),
    CONSTRAINT fk_worker_earning_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_earning_time FOREIGN KEY (work_time_entry_id) REFERENCES work_time_entries(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_earning_assignment FOREIGN KEY (work_assignment_id) REFERENCES work_assignments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_earning_period FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id) ON DELETE RESTRICT,
    CONSTRAINT fk_worker_earning_eligible_actor FOREIGN KEY (eligible_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_worker_earning_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_worker_earning_statement_line FOREIGN KEY (statement_line_id) REFERENCES worker_statement_lines(id) ON DELETE SET NULL,
    CONSTRAINT fk_worker_earning_voider FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_worker_earning_quantity CHECK (quantity >= 0),
    CONSTRAINT chk_worker_earning_amount CHECK (amount IS NULL OR amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_earning_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_earning_id CHAR(36) NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NOT NULL,
    reason VARCHAR(1000) NULL,
    event_snapshot JSON NOT NULL,
    actor_id INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_worker_earning_event (worker_earning_id,created_at),
    CONSTRAINT fk_worker_earning_event_earning FOREIGN KEY (worker_earning_id) REFERENCES worker_earnings(id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_earning_event_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_statement_earning := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_statement_lines' AND column_name='worker_earning_id');
SET @sql := IF(@has_statement_earning=0, 'ALTER TABLE worker_statement_lines ADD COLUMN worker_earning_id CHAR(36) NULL AFTER worker_statement_id, ADD UNIQUE KEY uq_statement_earning (worker_earning_id), ADD CONSTRAINT fk_statement_line_earning FOREIGN KEY (worker_earning_id) REFERENCES worker_earnings(id) ON DELETE RESTRICT', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Legacy hourly accruals and assignment compensation remain authoritative
-- source records; unified earnings point back to them with idempotent keys.
INSERT INTO worker_earnings
    (id,source_key,source_type,source_id,source_revision,worker_profile_id,work_time_entry_id,pay_period_id,status,method,quantity,rate,amount,currency,calculation_snapshot,approved_by,approved_at,settled_at,voided_at,created_at)
SELECT UUID(),CONCAT('time_entry:',s.time_entry_id,':',s.entry_revision),'time_entry',s.time_entry_id,s.entry_revision,wp.id,s.time_entry_id,
       (SELECT pp.id FROM pay_periods pp WHERE DATE(s.approved_at) BETWEEN pp.period_start AND pp.period_end ORDER BY pp.period_start DESC LIMIT 1),
       CASE a.status WHEN 'paid' THEN 'settled' WHEN 'voided' THEN 'voided' ELSE 'approved' END,
       'hourly',a.hours,a.rate,a.amount,a.currency,
       JSON_OBJECT('legacy_pay_accrual_id',a.id,'approval_snapshot_id',s.id,'employee_user_id',a.employee_user_id,'historical_source',a.historical_source),
       s.approved_by,s.approved_at,a.paid_at,CASE WHEN a.status='voided' THEN a.updated_at ELSE NULL END,a.created_at
FROM work_pay_accruals a
JOIN work_approval_snapshots s ON s.id=a.approval_snapshot_id
JOIN worker_profiles wp ON wp.user_id=a.employee_user_id
WHERE wp.relationship_type<>'owner'
ON DUPLICATE KEY UPDATE id=worker_earnings.id;

INSERT INTO worker_earnings
    (id,source_key,source_type,source_id,source_revision,worker_profile_id,work_assignment_id,pay_period_id,status,method,quantity,amount,currency,calculation_snapshot,eligible_by,eligible_at,approved_by,approved_at,settled_at,created_at)
SELECT UUID(),CONCAT('work_assignment:',wa.id,':1'),'work_assignment',CAST(wa.id AS CHAR),1,wa.worker_profile_id,wa.id,
       (SELECT pp.id FROM pay_periods pp WHERE DATE(COALESCE(wa.approved_at,wa.eligible_at,wa.completed_at)) BETWEEN pp.period_start AND pp.period_end ORDER BY pp.period_start DESC LIMIT 1),
       CASE wa.status WHEN 'settled' THEN 'settled' WHEN 'approved_payable' THEN 'approved' ELSE 'eligible' END,
       CASE JSON_UNQUOTE(JSON_EXTRACT(wa.compensation_snapshot,'$.method'))
           WHEN 'hourly' THEN 'hourly' WHEN 'fixed' THEN 'fixed' WHEN 'base_overage' THEN 'base_overage' WHEN 'percentage' THEN 'percentage' ELSE 'manual' END,
       COALESCE(jwc.planned_quantity,1),COALESCE(wa.approved_pay,wa.estimated_pay),wa.currency,
       JSON_OBJECT('legacy_work_assignment_id',wa.id,'compensation_snapshot',wa.compensation_snapshot,'eligibility_snapshot',wa.eligibility_snapshot),
       wa.eligible_by,wa.eligible_at,wa.approved_by,wa.approved_at,wa.settled_at,wa.created_at
FROM work_assignments wa
JOIN worker_profiles wp ON wp.id=wa.worker_profile_id
JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id
WHERE wa.status IN ('eligible','approved_payable','settled') AND wp.relationship_type<>'owner'
ON DUPLICATE KEY UPDATE id=worker_earnings.id;

UPDATE worker_statement_lines l
JOIN worker_earnings e ON e.source_type='time_entry' AND e.work_time_entry_id=l.work_time_entry_id
SET l.worker_earning_id=e.id
WHERE l.worker_earning_id IS NULL AND l.work_time_entry_id IS NOT NULL;

UPDATE worker_statement_lines l
JOIN worker_earnings e ON e.source_type='work_assignment' AND e.work_assignment_id=l.work_assignment_id
SET l.worker_earning_id=e.id
WHERE l.worker_earning_id IS NULL AND l.work_assignment_id IS NOT NULL;

UPDATE worker_earnings e
JOIN worker_statement_lines l ON l.worker_earning_id=e.id
JOIN worker_statements s ON s.id=l.worker_statement_id
SET e.statement_line_id=l.id,
    e.status=CASE WHEN s.status='settled' THEN 'settled' ELSE 'included' END,
    e.settled_at=CASE WHEN s.status='settled' THEN COALESCE(e.settled_at,s.settled_at) ELSE e.settled_at END;

INSERT INTO role_permissions (role_id,permission,allowed)
SELECT r.id,p.permission,1
FROM roles r JOIN (
    SELECT 'workforce.time.submit' permission UNION ALL
    SELECT 'workforce.time.review' UNION ALL
    SELECT 'workforce.billing.manage' UNION ALL
    SELECT 'workforce.earnings.manage' UNION ALL
    SELECT 'workforce.relationships.reconcile'
) p
WHERE r.name IN ('admin','owner') AND r.is_system=1
ON DUPLICATE KEY UPDATE allowed=VALUES(allowed);

INSERT INTO role_permissions (role_id,permission,allowed)
SELECT r.id,p.permission,1
FROM roles r JOIN (
    SELECT 'workforce.time.submit' permission UNION ALL
    SELECT 'workforce.earnings.self'
) p
WHERE r.name='employee' AND r.is_system=1
ON DUPLICATE KEY UPDATE allowed=VALUES(allowed);
