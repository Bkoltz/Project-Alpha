-- Migration 035: Invoice finalization lifecycle and disabled AutoPay beta foundation
-- AutoPay remains unavailable. This migration disables all legacy flags.

USE project_alpha;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='finalized_at');
SET @sql := IF(@has_col=0, 'ALTER TABLE invoices ADD COLUMN finalized_at TIMESTAMP NULL DEFAULT NULL AFTER sent_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='finalized_by');
SET @sql := IF(@has_col=0, 'ALTER TABLE invoices ADD COLUMN finalized_by INT NULL AFTER finalized_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='finalization_source');
SET @sql := IF(@has_col=0, 'ALTER TABLE invoices ADD COLUMN finalization_source VARCHAR(50) NULL AFTER finalized_by', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='collection_mode');
SET @sql := IF(@has_col=0, 'ALTER TABLE invoices ADD COLUMN collection_mode ENUM("direct","project_aggregate") NOT NULL DEFAULT "direct" AFTER finalization_source', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='stripe_checkout_expires_at');
SET @sql := IF(@has_col=0, 'ALTER TABLE invoices ADD COLUMN stripe_checkout_expires_at DATETIME NULL AFTER stripe_session_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='client_type');
SET @sql := IF(@has_col=0, 'ALTER TABLE clients ADD COLUMN client_type ENUM("unknown","business","consumer") NOT NULL DEFAULT "unknown" AFTER organization_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='project_invoices' AND column_name='finalized_at');
SET @sql := IF(@has_col=0, 'ALTER TABLE project_invoices ADD COLUMN finalized_at TIMESTAMP NULL DEFAULT NULL AFTER sent_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='project_invoices' AND column_name='finalization_source');
SET @sql := IF(@has_col=0, 'ALTER TABLE project_invoices ADD COLUMN finalization_source VARCHAR(50) NULL AFTER finalized_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='project_invoices' AND column_name='stripe_session_id');
SET @sql := IF(@has_col=0, 'ALTER TABLE project_invoices ADD COLUMN stripe_session_id VARCHAR(255) NULL AFTER finalization_source', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='project_invoices' AND column_name='stripe_checkout_expires_at');
SET @sql := IF(@has_col=0, 'ALTER TABLE project_invoices ADD COLUMN stripe_checkout_expires_at DATETIME NULL AFTER stripe_session_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing non-draft invoices were already exposed as collectible before this
-- lifecycle was introduced. Preserve that state without creating new charges.
UPDATE invoices
SET finalized_at=COALESCE(finalized_at,created_at),
    finalization_source=COALESCE(finalization_source,'legacy_migration')
WHERE status IN ('sent','unpaid','partial','paid','overdue');

UPDATE project_invoices
SET finalized_at=COALESCE(finalized_at,created_at),
    finalization_source=COALESCE(finalization_source,'legacy_migration')
WHERE status IN ('sent','unpaid','partial','paid');

CREATE TABLE IF NOT EXISTS autopay_authorizations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    client_id INT NOT NULL,
    scope_type ENUM('account','contract','project') NOT NULL,
    contract_id INT NULL,
    project_id INT NULL,
    status ENUM('pending','active','revoked','expired') NOT NULL DEFAULT 'pending',
    stripe_customer_id VARCHAR(255) NULL,
    stripe_payment_method_id VARCHAR(255) NULL,
    consent_version VARCHAR(50) NOT NULL,
    consent_snapshot MEDIUMTEXT NOT NULL,
    consent_email VARCHAR(255) NOT NULL,
    consent_ip VARCHAR(45) NULL,
    consent_user_agent VARCHAR(500) NULL,
    amount_limit DECIMAL(12,2) NULL,
    variable_notice_days SMALLINT NOT NULL DEFAULT 10,
    confirmed_at TIMESTAMP NULL,
    revoked_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_autopay_auth_client (client_id),
    INDEX idx_autopay_auth_scope (scope_type,contract_id,project_id),
    INDEX idx_autopay_auth_status (status),
    CONSTRAINT fk_autopay_auth_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_autopay_auth_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL,
    CONSTRAINT fk_autopay_auth_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autopay_authorization_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    authorization_id BIGINT NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    metadata JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_autopay_event_auth (authorization_id),
    CONSTRAINT fk_autopay_event_auth FOREIGN KEY (authorization_id) REFERENCES autopay_authorizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autopay_scheduled_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    authorization_id BIGINT NOT NULL,
    invoice_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    scheduled_for DATETIME NOT NULL,
    status ENUM('scheduled','processing','succeeded','failed','cancelled') NOT NULL DEFAULT 'scheduled',
    idempotency_key VARCHAR(100) NOT NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    last_error TEXT NULL,
    attempted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_autopay_attempt_idempotency (idempotency_key),
    INDEX idx_autopay_attempt_due (status,scheduled_for),
    INDEX idx_autopay_attempt_invoice (invoice_id),
    CONSTRAINT fk_autopay_attempt_auth FOREIGN KEY (authorization_id) REFERENCES autopay_authorizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_autopay_attempt_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autopay_advance_notices (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    scheduled_attempt_id BIGINT NOT NULL,
    email_to VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    charge_date DATE NOT NULL,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_autopay_notice_attempt (scheduled_attempt_id,email_to),
    CONSTRAINT fk_autopay_notice_attempt FOREIGN KEY (scheduled_attempt_id) REFERENCES autopay_scheduled_attempts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autopay_access_tokens (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    authorization_id BIGINT NOT NULL,
    purpose ENUM('confirm','manage','revoke','recover') NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at TIMESTAMP NULL,
    attempts SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_autopay_token_hash (token_hash),
    INDEX idx_autopay_token_auth (authorization_id),
    CONSTRAINT fk_autopay_token_auth FOREIGN KEY (authorization_id) REFERENCES autopay_authorizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    stripe_event_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    status ENUM('processing','processed','failed') NOT NULL DEFAULT 'processing',
    attempts SMALLINT NOT NULL DEFAULT 1,
    last_error TEXT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stripe_event_id (stripe_event_id),
    INDEX idx_stripe_event_status (status,received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_receipts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    invoice_id INT NULL,
    receipt_number VARCHAR(50) NOT NULL,
    public_token VARCHAR(64) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    email_to VARCHAR(255) NULL,
    emailed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_receipt_payment (payment_id),
    UNIQUE KEY uq_payment_receipt_number (receipt_number),
    UNIQUE KEY uq_payment_receipt_token (public_token),
    CONSTRAINT fk_payment_receipt_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_receipt_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_refunds (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    stripe_refund_id VARCHAR(255) NOT NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    payment_id INT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status VARCHAR(50) NOT NULL,
    reason VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stripe_refund_id (stripe_refund_id),
    INDEX idx_stripe_refund_payment (payment_id),
    CONSTRAINT fk_stripe_refund_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_disputes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    stripe_dispute_id VARCHAR(255) NOT NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    payment_id INT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status VARCHAR(50) NOT NULL,
    reason VARCHAR(100) NULL,
    evidence_due_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stripe_dispute_id (stripe_dispute_id),
    INDEX idx_stripe_dispute_payment (payment_id),
    CONSTRAINT fk_stripe_dispute_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_invoice_payments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    project_invoice_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    disputed_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_method VARCHAR(30) NOT NULL DEFAULT 'stripe',
    stripe_session_id VARCHAR(255) NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    status ENUM('processing','succeeded','failed') NOT NULL DEFAULT 'processing',
    payment_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_payment_session (stripe_session_id),
    UNIQUE KEY uq_project_payment_intent (stripe_payment_intent_id),
    INDEX idx_project_payment_parent (project_invoice_id),
    CONSTRAINT fk_project_payment_parent FOREIGN KEY (project_invoice_id) REFERENCES project_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stripe_refunds' AND column_name='project_invoice_payment_id');
SET @sql := IF(@has_col=0, 'ALTER TABLE stripe_refunds ADD COLUMN project_invoice_payment_id BIGINT NULL AFTER payment_id, ADD INDEX idx_stripe_refund_project_payment (project_invoice_payment_id), ADD CONSTRAINT fk_stripe_refund_project_payment FOREIGN KEY (project_invoice_payment_id) REFERENCES project_invoice_payments(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stripe_disputes' AND column_name='project_invoice_payment_id');
SET @sql := IF(@has_col=0, 'ALTER TABLE stripe_disputes ADD COLUMN project_invoice_payment_id BIGINT NULL AFTER payment_id, ADD INDEX idx_stripe_dispute_project_payment (project_invoice_payment_id), ADD CONSTRAINT fk_stripe_dispute_project_payment FOREIGN KEY (project_invoice_payment_id) REFERENCES project_invoice_payments(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payments' AND column_name='project_invoice_payment_id');
SET @sql := IF(@has_col=0, 'ALTER TABLE payments ADD COLUMN project_invoice_payment_id BIGINT NULL AFTER invoice_id, ADD INDEX idx_payments_project_payment (project_invoice_payment_id), ADD CONSTRAINT fk_payments_project_payment FOREIGN KEY (project_invoice_payment_id) REFERENCES project_invoice_payments(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payments' AND column_name='refunded_amount');
SET @sql := IF(@has_col=0, 'ALTER TABLE payments ADD COLUMN refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payments' AND column_name='disputed_amount');
SET @sql := IF(@has_col=0, 'ALTER TABLE payments ADD COLUMN disputed_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER refunded_amount', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='audit_schedules' AND column_name='accounting_basis');
SET @sql := IF(@has_col=0, 'ALTER TABLE audit_schedules ADD COLUMN accounting_basis ENUM("cash","accrual") NOT NULL DEFAULT "cash" AFTER date_range_type', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @duplicate_pi := (SELECT COUNT(*) FROM (SELECT stripe_payment_intent_id FROM payments WHERE stripe_payment_intent_id IS NOT NULL GROUP BY stripe_payment_intent_id HAVING COUNT(*) > 1) duplicate_rows);
SET @has_unique_pi := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='payments' AND index_name='uq_payments_stripe_pi');
SET @sql := IF(@duplicate_pi=0 AND @has_unique_pi=0, 'ALTER TABLE payments ADD UNIQUE KEY uq_payments_stripe_pi (stripe_payment_intent_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @duplicate_session := (SELECT COUNT(*) FROM (SELECT stripe_session_id FROM payments WHERE stripe_session_id IS NOT NULL GROUP BY stripe_session_id HAVING COUNT(*) > 1) duplicate_rows);
SET @has_unique_session := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='payments' AND index_name='uq_payments_stripe_session');
SET @sql := IF(@duplicate_session=0 AND @has_unique_session=0, 'ALTER TABLE payments ADD UNIQUE KEY uq_payments_stripe_session (stripe_session_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing flags never count as consent. Disable them during every safe retry.
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='auto_pay_enabled');
SET @sql := IF(@has_col>0, 'UPDATE clients SET auto_pay_enabled=0 WHERE auto_pay_enabled<>0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='contracts' AND column_name='auto_pay_enabled');
SET @sql := IF(@has_col>0, 'UPDATE contracts SET auto_pay_enabled=0 WHERE auto_pay_enabled<>0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
