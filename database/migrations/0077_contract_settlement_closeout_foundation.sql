-- Migration 0077: default-off contract settlement and project close-out schema foundation.
-- This migration creates no settlement terms, reviews, invoices, or status
-- transitions for existing records.

SET @pa_contract_status_supports_closing := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contracts' AND COLUMN_NAME='status'
    AND COLUMN_TYPE LIKE '%''closing''%'
);
SET @pa_sql := IF(@pa_contract_status_supports_closing=0,
  'ALTER TABLE contracts MODIFY COLUMN status ENUM(''draft'',''pending'',''active'',''paused'',''closing'',''completed'',''cancelled'',''denied'',''void'') NOT NULL DEFAULT ''pending''',
  'SELECT 1');
PREPARE pa_stmt FROM @pa_sql; EXECUTE pa_stmt; DEALLOCATE PREPARE pa_stmt;

CREATE TABLE IF NOT EXISTS contract_settlement_terms (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT NOT NULL,
  project_id INT NOT NULL,
  contract_id INT NOT NULL,
  contract_revision INT UNSIGNED NOT NULL,
  policy_mode ENUM('none','reprice_to_percentage','manual_review') NOT NULL DEFAULT 'none',
  commitment_end_date DATE NULL,
  target_definition_id BIGINT UNSIGNED NULL,
  frozen_target_name VARCHAR(150) NULL,
  frozen_target_kind VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
  frozen_target_percentage DECIMAL(7,4) NULL,
  created_by INT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_contract_settlement_terms_revision (contract_id,contract_revision),
  KEY idx_contract_settlement_terms_scope (organization_id,project_id,contract_id),
  KEY idx_contract_settlement_terms_definition (target_definition_id),
  CONSTRAINT chk_contract_settlement_terms_policy CHECK (
    (policy_mode='reprice_to_percentage'
      AND commitment_end_date IS NOT NULL
      AND target_definition_id IS NOT NULL
      AND frozen_target_name IS NOT NULL
      AND frozen_target_kind='percentage_discount'
      AND frozen_target_percentage IS NOT NULL
      AND frozen_target_percentage>=0
      AND frozen_target_percentage<=100)
    OR
    (policy_mode IN ('none','manual_review')
      AND target_definition_id IS NULL
      AND frozen_target_name IS NULL
      AND frozen_target_kind IS NULL
      AND frozen_target_percentage IS NULL)
  ),
  CONSTRAINT fk_contract_settlement_terms_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_settlement_terms_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_settlement_terms_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_settlement_terms_definition FOREIGN KEY (target_definition_id) REFERENCES pricing_adjustment_definitions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_settlement_terms_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_settlements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16)))),
  organization_id INT NOT NULL,
  project_id INT NOT NULL,
  contract_id INT NOT NULL,
  contract_revision INT UNSIGNED NOT NULL,
  settlement_terms_id BIGINT UNSIGNED NULL,
  request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  basis_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('review_required','draft_created','no_adjustment','waived','cancelled','manual_review_required') NOT NULL,
  prior_contract_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actual_end_date DATE NOT NULL,
  currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
  subtotal_delta_minor BIGINT NULL,
  tax_delta_minor BIGINT NULL,
  total_delta_minor BIGINT NULL,
  calculation_version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'contract-settlement-v1',
  basis_json JSON NULL,
  draft_invoice_id INT NULL,
  requested_by INT NULL,
  requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  reviewed_by INT NULL,
  reviewed_at DATETIME(6) NULL,
  decision_reason VARCHAR(500) NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_contract_settlements_public_id (public_id),
  UNIQUE KEY uq_contract_settlements_request (contract_id,request_key),
  UNIQUE KEY uq_contract_settlements_basis (contract_id,basis_hash),
  UNIQUE KEY uq_contract_settlements_draft_invoice (draft_invoice_id),
  KEY idx_contract_settlements_project_status (project_id,status),
  KEY idx_contract_settlements_contract_status (contract_id,status),
  KEY idx_contract_settlements_terms (settlement_terms_id),
  CONSTRAINT chk_contract_settlements_currency CHECK (currency IS NULL OR currency REGEXP '^[A-Z]{3}$'),
  CONSTRAINT chk_contract_settlements_totals CHECK (
    (subtotal_delta_minor IS NULL AND tax_delta_minor IS NULL AND total_delta_minor IS NULL)
    OR
    (subtotal_delta_minor IS NOT NULL AND tax_delta_minor IS NOT NULL AND total_delta_minor IS NOT NULL
      AND total_delta_minor=subtotal_delta_minor+tax_delta_minor)
  ),
  CONSTRAINT fk_contract_settlements_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_settlements_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_settlements_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_settlements_terms FOREIGN KEY (settlement_terms_id) REFERENCES contract_settlement_terms(id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_settlements_draft_invoice FOREIGN KEY (draft_invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_settlements_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_settlements_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_settlement_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  settlement_id BIGINT UNSIGNED NOT NULL,
  source_invoice_id INT NOT NULL,
  source_revision INT UNSIGNED NOT NULL,
  source_pricing_snapshot_id BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  basis_minor BIGINT UNSIGNED NOT NULL,
  historical_adjustment_minor BIGINT UNSIGNED NOT NULL,
  target_percentage_rate DECIMAL(7,4) NOT NULL,
  target_adjustment_minor BIGINT UNSIGNED NOT NULL,
  historical_total_minor BIGINT UNSIGNED NOT NULL,
  target_total_minor BIGINT UNSIGNED NOT NULL,
  delta_minor BIGINT NOT NULL,
  source_content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_contract_settlement_line_source (settlement_id,source_invoice_id,source_revision),
  KEY idx_contract_settlement_line_invoice (source_invoice_id,source_revision),
  KEY idx_contract_settlement_line_snapshot (source_pricing_snapshot_id),
  CONSTRAINT chk_contract_settlement_line_currency CHECK (currency REGEXP '^[A-Z]{3}$'),
  CONSTRAINT chk_contract_settlement_line_rate CHECK (target_percentage_rate>=0 AND target_percentage_rate<=100),
  CONSTRAINT chk_contract_settlement_line_values CHECK (
    historical_adjustment_minor<=basis_minor
    AND target_adjustment_minor<=basis_minor
    AND delta_minor=target_total_minor-historical_total_minor
  ),
  CONSTRAINT fk_contract_settlement_line_settlement FOREIGN KEY (settlement_id) REFERENCES contract_settlements(id) ON DELETE CASCADE,
  CONSTRAINT fk_contract_settlement_line_invoice FOREIGN KEY (source_invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_contract_settlement_line_snapshot FOREIGN KEY (source_pricing_snapshot_id) REFERENCES document_pricing_adjustment_snapshots(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_config (organization_id,config_key,config_value)
VALUES (0,'contract_settlement_enabled','0')
ON DUPLICATE KEY UPDATE config_value=config_value;
