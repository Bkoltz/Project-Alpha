-- Migration 0072: generic, default-off pricing-adjustment foundation. No assignments or backfill.
SET @pricing_project_invoice_revision_exists := (
 SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='project_invoices' AND column_name='revision_number'
);
SET @pricing_project_invoice_revision_sql := IF(
 @pricing_project_invoice_revision_exists=0,
 'ALTER TABLE project_invoices ADD COLUMN revision_number INT UNSIGNED NOT NULL DEFAULT 1 AFTER doc_number',
 'SELECT 1'
);
PREPARE pricing_project_invoice_revision_stmt FROM @pricing_project_invoice_revision_sql;
EXECUTE pricing_project_invoice_revision_stmt;
DEALLOCATE PREPARE pricing_project_invoice_revision_stmt;
CREATE TABLE IF NOT EXISTS pricing_adjustment_definitions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, organization_id INT NOT NULL,
 name VARCHAR(150) NOT NULL, adjustment_kind VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'percentage_discount',
 percentage_rate DECIMAL(7,4) NOT NULL, effective_from DATE NULL, effective_until DATE NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1, created_by INT NULL, updated_by INT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_pricing_adjustment_name (organization_id,name), KEY idx_pricing_adjustment_eligibility (organization_id,is_active,effective_from,effective_until),
 CONSTRAINT chk_pricing_adjustment_kind CHECK (adjustment_kind='percentage_discount'),
 CONSTRAINT chk_pricing_adjustment_rate CHECK (percentage_rate>0 AND percentage_rate<=100),
 CONSTRAINT chk_pricing_adjustment_dates CHECK (effective_until IS NULL OR effective_from IS NULL OR effective_until>=effective_from),
 CONSTRAINT fk_pricing_adjustment_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_pricing_adjustment_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_pricing_adjustment_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_pricing_adjustment_assignments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, organization_id INT NOT NULL, project_id INT NOT NULL,
 adjustment_definition_id BIGINT UNSIGNED NOT NULL, assigned_by INT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_project_pricing_adjustment (project_id), KEY idx_project_pricing_adjustment_org (organization_id,project_id),
 CONSTRAINT fk_project_pricing_adjustment_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_project_pricing_adjustment_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
 CONSTRAINT fk_project_pricing_adjustment_definition FOREIGN KEY (adjustment_definition_id) REFERENCES pricing_adjustment_definitions(id) ON DELETE RESTRICT,
 CONSTRAINT fk_project_pricing_adjustment_actor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_pricing_adjustment_assignments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, organization_id INT NOT NULL, contract_id INT NOT NULL,
 adjustment_definition_id BIGINT UNSIGNED NOT NULL, assigned_by INT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_contract_pricing_adjustment (contract_id), KEY idx_contract_pricing_adjustment_org (organization_id,contract_id),
 CONSTRAINT fk_contract_pricing_adjustment_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_contract_pricing_adjustment_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
 CONSTRAINT fk_contract_pricing_adjustment_definition FOREIGN KEY (adjustment_definition_id) REFERENCES pricing_adjustment_definitions(id) ON DELETE RESTRICT,
 CONSTRAINT fk_contract_pricing_adjustment_actor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_pricing_adjustment_overrides (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, organization_id INT NOT NULL,
 document_type ENUM('quote','contract','invoice','project_invoice') NOT NULL, document_id INT NOT NULL,
 override_mode ENUM('adjustment','none') NOT NULL, adjustment_definition_id BIGINT UNSIGNED NULL,
 reason VARCHAR(500) NOT NULL, created_by INT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_document_pricing_override (document_type,document_id), KEY idx_document_pricing_override_org (organization_id,document_type,document_id),
 CONSTRAINT chk_document_pricing_override_definition CHECK ((override_mode='adjustment' AND adjustment_definition_id IS NOT NULL) OR (override_mode='none' AND adjustment_definition_id IS NULL)),
 CONSTRAINT fk_document_pricing_override_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_document_pricing_override_definition FOREIGN KEY (adjustment_definition_id) REFERENCES pricing_adjustment_definitions(id) ON DELETE RESTRICT,
 CONSTRAINT fk_document_pricing_override_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_pricing_adjustment_snapshots (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, organization_id INT NOT NULL,
 document_type ENUM('quote','contract','invoice','project_invoice') NOT NULL, document_id INT NOT NULL,
 document_revision INT UNSIGNED NOT NULL DEFAULT 1, source_type ENUM('document_override','contract','project','none') NOT NULL,
 source_assignment_id BIGINT UNSIGNED NULL, adjustment_definition_id BIGINT UNSIGNED NULL,
 adjustment_name VARCHAR(150) NULL, adjustment_kind VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
 percentage_rate DECIMAL(7,4) NULL, currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 basis_minor BIGINT UNSIGNED NOT NULL, adjustment_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
 adjusted_minor BIGINT UNSIGNED NOT NULL, calculation_version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'percentage-v1',
 override_reason VARCHAR(500) NULL, applied_by INT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_document_pricing_snapshot (document_type,document_id,document_revision), KEY idx_document_pricing_snapshot_org (organization_id,document_type,document_id),
 CONSTRAINT chk_document_pricing_snapshot_values CHECK (adjustment_minor<=basis_minor AND adjusted_minor=basis_minor-adjustment_minor),
 CONSTRAINT fk_document_pricing_snapshot_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT,
 CONSTRAINT fk_document_pricing_snapshot_definition FOREIGN KEY (adjustment_definition_id) REFERENCES pricing_adjustment_definitions(id) ON DELETE RESTRICT,
 CONSTRAINT fk_document_pricing_snapshot_actor FOREIGN KEY (applied_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_config (organization_id,config_key,config_value) VALUES (0,'pricing_adjustments_enabled','0')
ON DUPLICATE KEY UPDATE config_value=config_value;
