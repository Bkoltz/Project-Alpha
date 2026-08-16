-- Migration 0066: generic, default-off portal v2 integration contracts.
-- Runtime application keys, labels, routes and credentials are operator-owned.

SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='organization_departments' AND column_name='public_id')=0,'ALTER TABLE organization_departments ADD COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id','SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='organization_departments' AND column_name='source_version')=0,"ALTER TABLE organization_departments ADD COLUMN source_version VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '1' AFTER public_id",'SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
UPDATE organization_departments SET public_id=LOWER(HEX(RANDOM_BYTES(16))) WHERE public_id IS NULL;
ALTER TABLE organization_departments
    MODIFY COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16))));
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='organization_departments' AND index_name='uq_organization_departments_public_id')=0,'ALTER TABLE organization_departments ADD UNIQUE KEY uq_organization_departments_public_id (public_id)','SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;

SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='organizations' AND column_name='source_version')=0,"ALTER TABLE organizations ADD COLUMN source_version VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '1' AFTER public_id",'SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='source_version')=0,"ALTER TABLE clients ADD COLUMN source_version VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '1' AFTER public_id",'SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='projects' AND column_name='source_version')=0,"ALTER TABLE projects ADD COLUMN source_version VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '1' AFTER public_id",'SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='projects' AND column_name='completed_at')=0,'ALTER TABLE projects ADD COLUMN completed_at DATETIME(6) NULL AFTER status','SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='projects' AND index_name='idx_projects_portal_lifecycle')=0,'ALTER TABLE projects ADD INDEX idx_projects_portal_lifecycle (status,completed_at)','SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;

SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='portal_principals' AND column_name='email_hint')=0,'ALTER TABLE portal_principals ADD COLUMN email_hint VARCHAR(254) NULL AFTER public_id','SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='portal_principals' AND column_name='display_name')=0,'ALTER TABLE portal_principals ADD COLUMN display_name VARCHAR(150) NULL AFTER email_hint','SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='portal_principals' AND column_name='source_version')=0,"ALTER TABLE portal_principals ADD COLUMN source_version VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '1' AFTER display_name",'SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;

CREATE TABLE IF NOT EXISTS portal_integration_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    application_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_label VARCHAR(100) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    portal_projection_enabled TINYINT(1) NOT NULL DEFAULT 0,
    relation_projection_enabled TINYINT(1) NOT NULL DEFAULT 0,
    catalog_projection_enabled TINYINT(1) NOT NULL DEFAULT 0,
    pricing_preview_enabled TINYINT(1) NOT NULL DEFAULT 0,
    draft_quote_enabled TINYINT(1) NOT NULL DEFAULT 0,
    pricing_source VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    draft_source VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    portal_route VARCHAR(500) NULL,
    catalog_route VARCHAR(500) NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_portal_integration_application_key (application_key),
    CONSTRAINT fk_portal_integration_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_portal_integration_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_portal_integration_application_key CHECK (application_key REGEXP '^[a-z0-9][a-z0-9_-]{1,63}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_v2_entitlements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16)))),
    portal_principal_id BIGINT UNSIGNED NOT NULL,
    capability VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    effect ENUM('allow','deny') NOT NULL,
    scope_type ENUM('workspace','organization','standalone_client','department','client','project') NOT NULL,
    scope_public_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_version VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '1',
    active TINYINT(1) NOT NULL DEFAULT 0,
    valid_from DATETIME(6) NULL,
    expires_at DATETIME(6) NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_portal_v2_entitlement_public_id (public_id),
    KEY idx_portal_v2_entitlement_principal (portal_principal_id, active, expires_at),
    KEY idx_portal_v2_entitlement_scope (scope_type, scope_public_id, capability, active),
    CONSTRAINT fk_portal_v2_entitlement_principal FOREIGN KEY (portal_principal_id) REFERENCES portal_principals(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_v2_entitlement_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_portal_v2_entitlement_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_portal_v2_entitlement_capability CHECK (capability IN ('workspace.view','directory.read','request.create','delivery.view','member.manage','share.create'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_v2_workspaces (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16)))),
    root_type ENUM('organization','standalone_client') NOT NULL,
    root_public_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    source_version VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '1',
    active TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_portal_v2_workspace_public_id (public_id),
    UNIQUE KEY uq_portal_v2_workspace_root (root_type,root_public_id),
    CONSTRAINT fk_portal_v2_workspace_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_portal_v2_workspace_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_integration_profile_workspaces (
    profile_id BIGINT UNSIGNED NOT NULL,
    workspace_id BIGINT UNSIGNED NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (profile_id,workspace_id),
    KEY idx_portal_profile_workspace_reverse (workspace_id,active,profile_id),
    CONSTRAINT fk_portal_profile_workspace_profile FOREIGN KEY (profile_id) REFERENCES portal_integration_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_profile_workspace_workspace FOREIGN KEY (workspace_id) REFERENCES portal_v2_workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_profile_workspace_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_portal_profile_workspace_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_v2_relations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16)))),
    relation_type ENUM('contains','contact_assignment') NOT NULL,
    from_type ENUM('organization','standalone_client','department','client','project') NOT NULL,
    from_public_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    to_type ENUM('department','client','project','contact') NOT NULL,
    to_public_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_version VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '1',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_portal_v2_relation_public_id (public_id),
    UNIQUE KEY uq_portal_v2_relation_edge (relation_type,from_type,from_public_id,to_type,to_public_id),
    KEY idx_portal_v2_relation_from (from_type,from_public_id,active),
    KEY idx_portal_v2_relation_to (to_type,to_public_id,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_v2_contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16)))),
    client_id INT NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    source_version VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '1',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_portal_v2_contact_public_id (public_id),
    UNIQUE KEY uq_portal_v2_contact_client (client_id),
    CONSTRAINT fk_portal_v2_contact_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO portal_v2_contacts (client_id,display_name)
SELECT DISTINCT c.id,c.name FROM organization_department_contacts dc JOIN clients c ON c.id=dc.client_id;

INSERT IGNORE INTO portal_v2_relations (relation_type,from_type,from_public_id,to_type,to_public_id)
SELECT 'contains','organization',o.public_id,'department',d.public_id FROM organization_departments d JOIN organizations o ON o.id=d.organization_id;
INSERT IGNORE INTO portal_v2_relations (relation_type,from_type,from_public_id,to_type,to_public_id)
SELECT 'contains','organization',o.public_id,'client',c.public_id FROM clients c JOIN organizations o ON o.id=c.organization_id;
INSERT IGNORE INTO portal_v2_relations (relation_type,from_type,from_public_id,to_type,to_public_id)
SELECT 'contains','organization',o.public_id,'project',p.public_id FROM projects p JOIN organizations o ON o.id=p.organization_id;
INSERT IGNORE INTO portal_v2_relations (relation_type,from_type,from_public_id,to_type,to_public_id)
SELECT 'contains','standalone_client',c.public_id,'project',p.public_id FROM projects p JOIN clients c ON c.id=p.client_id WHERE c.organization_id IS NULL;
INSERT IGNORE INTO portal_v2_relations (relation_type,from_type,from_public_id,to_type,to_public_id)
SELECT 'contains','department',d.public_id,'project',p.public_id FROM projects p JOIN organization_departments d ON d.id=p.department_id;
INSERT IGNORE INTO portal_v2_relations (relation_type,from_type,from_public_id,to_type,to_public_id)
SELECT 'contains','client',c.public_id,'project',p.public_id FROM projects p JOIN clients c ON c.id=p.client_id;
INSERT IGNORE INTO portal_v2_relations (relation_type,from_type,from_public_id,to_type,to_public_id)
SELECT 'contact_assignment','department',d.public_id,'contact',pc.public_id FROM organization_department_contacts dc JOIN organization_departments d ON d.id=dc.department_id JOIN portal_v2_contacts pc ON pc.client_id=dc.client_id;

SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='item_library' AND column_name='portal_public_id')=0,'ALTER TABLE item_library ADD COLUMN portal_public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id','SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='item_library' AND column_name='portal_source_version')=0,"ALTER TABLE item_library ADD COLUMN portal_source_version VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '1' AFTER portal_public_id",'SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='item_library' AND column_name='portal_requestable')=0,'ALTER TABLE item_library ADD COLUMN portal_requestable TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active','SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='item_library' AND column_name='portal_summary')=0,'ALTER TABLE item_library ADD COLUMN portal_summary VARCHAR(1000) NULL AFTER portal_requestable','SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='item_library' AND column_name='portal_category')=0,'ALTER TABLE item_library ADD COLUMN portal_category VARCHAR(100) NULL AFTER portal_summary','SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='item_library' AND column_name='portal_display_order')=0,'ALTER TABLE item_library ADD COLUMN portal_display_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER portal_category','SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='item_library' AND column_name='portal_geometry_requirement')=0,"ALTER TABLE item_library ADD COLUMN portal_geometry_requirement ENUM('none','optional','required') NOT NULL DEFAULT 'optional' AFTER portal_display_order",'SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='item_library' AND column_name='portal_questions_json')=0,'ALTER TABLE item_library ADD COLUMN portal_questions_json JSON NULL AFTER portal_geometry_requirement','SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='item_library' AND index_name='uq_item_library_portal_public_id')=0,'ALTER TABLE item_library ADD UNIQUE KEY uq_item_library_portal_public_id (portal_public_id)','SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='item_library' AND index_name='idx_item_library_portal_projection')=0,'ALTER TABLE item_library ADD INDEX idx_item_library_portal_projection (portal_requestable,is_active,entry_type,portal_display_order)','SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;

SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quotes' AND column_name='public_id')=0,'ALTER TABLE quotes ADD COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id','SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quotes' AND column_name='source_version')=0,"ALTER TABLE quotes ADD COLUMN source_version VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '1' AFTER public_id",'SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;
UPDATE quotes SET public_id=LOWER(HEX(RANDOM_BYTES(16))) WHERE public_id IS NULL;
ALTER TABLE quotes
    MODIFY COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16))));
SET @portal_v2_sql=IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='quotes' AND index_name='uq_quotes_public_id')=0,'ALTER TABLE quotes ADD UNIQUE KEY uq_quotes_public_id (public_id)','SELECT 1');PREPARE portal_v2_stmt FROM @portal_v2_sql;EXECUTE portal_v2_stmt;DEALLOCATE PREPARE portal_v2_stmt;

CREATE TABLE IF NOT EXISTS portal_draft_quote_commands (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    integration_profile_id BIGINT UNSIGNED NOT NULL,
    api_key_id INT NOT NULL,
    idempotency_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    receipt_public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16)))),
    quote_id INT NOT NULL,
    response_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_portal_draft_idempotency (integration_profile_id,api_key_id,idempotency_hash),
    UNIQUE KEY uq_portal_draft_receipt (receipt_public_id),
    KEY idx_portal_draft_quote (quote_id),
    CONSTRAINT fk_portal_draft_profile FOREIGN KEY (integration_profile_id) REFERENCES portal_integration_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_portal_draft_api_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE RESTRICT,
    CONSTRAINT fk_portal_draft_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_integration_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16)))),
    integration_profile_id BIGINT UNSIGNED NOT NULL,
    api_key_id INT NULL,
    action VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    target_public_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    metadata_json JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_portal_integration_audit_event (event_public_id),
    KEY idx_portal_integration_audit_profile (integration_profile_id,created_at),
    CONSTRAINT fk_portal_integration_audit_profile FOREIGN KEY (integration_profile_id) REFERENCES portal_integration_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_portal_integration_audit_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_projection_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    integration_profile_id BIGINT UNSIGNED NOT NULL,
    delivery_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    workspace_public_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    schema_version SMALLINT UNSIGNED NOT NULL,
    source_sequence BIGINT UNSIGNED NOT NULL,
    delivery_kind ENUM('snapshot.page','snapshot.activate','event','catalog.snapshot') NOT NULL,
    route_type ENUM('portal','catalog') NOT NULL,
    payload_json JSON NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    delivered_at DATETIME(6) NULL,
    last_error_code VARCHAR(64) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_portal_projection_delivery (delivery_id),
    KEY idx_portal_projection_due (integration_profile_id,delivered_at,next_attempt_at,id),
    CONSTRAINT fk_portal_projection_profile FOREIGN KEY (integration_profile_id) REFERENCES portal_integration_profiles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_projection_state (
    integration_profile_id BIGINT UNSIGNED NOT NULL,
    workspace_public_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_generation CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (integration_profile_id,workspace_public_id),
    CONSTRAINT fk_portal_projection_state_profile FOREIGN KEY (integration_profile_id) REFERENCES portal_integration_profiles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_number_sequences (
    document_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    document_subtype VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    next_number BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (document_type,document_subtype)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO document_number_sequences (document_type,document_subtype,next_number)
SELECT 'quote','regular',COALESCE(MAX(doc_number),0)+1 FROM quotes WHERE quote_type='regular'
ON DUPLICATE KEY UPDATE next_number=GREATEST(next_number,VALUES(next_number));

CREATE TABLE IF NOT EXISTS portal_integration_request_receipts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    integration_profile_id BIGINT UNSIGNED NOT NULL,
    api_key_id INT NOT NULL,
    capability VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    signature_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    body_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    replay_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_portal_integration_signature (integration_profile_id,api_key_id,capability,signature_hash),
    KEY idx_portal_integration_receipt_age (last_seen_at,id),
    CONSTRAINT fk_portal_request_profile FOREIGN KEY (integration_profile_id) REFERENCES portal_integration_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_portal_request_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_integration_rate_buckets (
    integration_profile_id BIGINT UNSIGNED NOT NULL,
    api_key_id INT NOT NULL,
    capability VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_minute BIGINT UNSIGNED NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (integration_profile_id,api_key_id,capability,source_hash,window_minute),
    KEY idx_portal_rate_bucket_age (window_minute,integration_profile_id,api_key_id,capability,source_hash),
    CONSTRAINT fk_portal_rate_profile FOREIGN KEY (integration_profile_id) REFERENCES portal_integration_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_rate_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO document_number_sequences (document_type,document_subtype,next_number)
SELECT 'quote','long_term',COALESCE(MAX(doc_number),0)+1 FROM quotes WHERE quote_type='long_term'
ON DUPLICATE KEY UPDATE next_number=GREATEST(next_number,VALUES(next_number));
INSERT INTO document_number_sequences (document_type,document_subtype,next_number)
SELECT 'quote','on_demand',COALESCE(MAX(doc_number),0)+1 FROM quotes WHERE quote_type='on_demand'
ON DUPLICATE KEY UPDATE next_number=GREATEST(next_number,VALUES(next_number));

INSERT IGNORE INTO app_config (organization_id,config_key,config_value) VALUES
    (0,'portal_v2_integration_enabled','0'),
    (0,'portal_v2_relations_enabled','0'),
    (0,'portal_catalog_v2_enabled','0'),
    (0,'portal_pricing_preview_enabled','0'),
    (0,'portal_draft_quotes_enabled','0');
