-- Business email providers, reusable addresses, first-class jobs, document revisions,
-- adjustable invoices, route suggestions, and calendar-ready scheduling.

INSERT INTO app_config (organization_id, config_key, config_value) VALUES
    (0, 'address_route_assistance_enabled', '0'),
    (0, 'job_project_locations_enabled', '0')
ON DUPLICATE KEY UPDATE config_value=VALUES(config_value);

CREATE TABLE IF NOT EXISTS email_provider_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider ENUM('smtp','gmail') NOT NULL,
    display_name VARCHAR(150) NULL,
    sender_email VARCHAR(255) NULL,
    sender_name VARCHAR(255) NULL,
    credentials_enc LONGTEXT NOT NULL,
    status ENUM('configured','connected','reauth_required','disabled','error') NOT NULL DEFAULT 'configured',
    token_expires_at DATETIME NULL,
    last_verified_at DATETIME NULL,
    last_error VARCHAR(1000) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_provider (provider),
    INDEX idx_email_provider_status (status),
    CONSTRAINT fk_email_provider_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_provider_state (
    id TINYINT NOT NULL PRIMARY KEY,
    active_connection_id INT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_state_connection FOREIGN KEY (active_connection_id) REFERENCES email_provider_connections(id) ON DELETE SET NULL,
    CONSTRAINT fk_email_state_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO email_provider_state (id,active_connection_id) VALUES (1,NULL) ON DUPLICATE KEY UPDATE id=VALUES(id);

CREATE TABLE IF NOT EXISTS email_delivery_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    message_key VARCHAR(190) NOT NULL,
    provider_connection_id INT NULL,
    document_type ENUM('quote','contract','invoice','project_invoice','onboarding','notification','other') NOT NULL DEFAULT 'other',
    document_id INT NULL,
    document_revision INT NULL,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    provider_message_id VARCHAR(255) NULL,
    status ENUM('pending','sent','failed','unknown') NOT NULL DEFAULT 'pending',
    error_message VARCHAR(1000) NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_delivery_message_key (message_key),
    INDEX idx_email_delivery_document (document_type,document_id,document_revision),
    INDEX idx_email_delivery_status (status,created_at),
    CONSTRAINT fk_email_delivery_provider FOREIGN KEY (provider_connection_id) REFERENCES email_provider_connections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(150) NULL,
    address_line1 VARCHAR(255) NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(100) NULL,
    postal_code VARCHAR(32) NULL,
    country VARCHAR(100) NULL DEFAULT 'US',
    google_place_id VARCHAR(255) NULL,
    source ENUM('manual','google') NOT NULL DEFAULT 'manual',
    legacy_key VARCHAR(190) NULL,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_address_legacy_key (legacy_key),
    INDEX idx_address_place_id (google_place_id),
    INDEX idx_address_archived (archived),
    CONSTRAINT fk_address_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS address_assignments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    address_id INT NOT NULL,
    entity_type ENUM('client','organization','project','job') NOT NULL,
    entity_id INT NOT NULL,
    purpose ENUM('billing','mailing','service','other') NOT NULL DEFAULT 'other',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_address_assignment (entity_type,entity_id,purpose,address_id),
    INDEX idx_address_assignment_default (entity_type,entity_id,purpose,is_default),
    CONSTRAINT fk_address_assignment_address FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_service_address_id := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='service_locations' AND column_name='address_id');
SET @sql := IF(@has_service_address_id=0, 'ALTER TABLE service_locations ADD COLUMN address_id INT NULL AFTER project_id, ADD INDEX idx_service_location_address (address_id), ADD CONSTRAINT fk_service_location_address FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    organization_id INT NULL,
    project_id INT NULL,
    job_code VARCHAR(64) NOT NULL,
    status ENUM('not_started','active','completed','cancelled') NOT NULL DEFAULT 'not_started',
    default_service_location_id INT NULL,
    notes TEXT NULL,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_job_client_code (client_id,job_code),
    INDEX idx_job_project (project_id),
    INDEX idx_job_created (created_at),
    INDEX idx_job_archived (archived),
    CONSTRAINT fk_job_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_job_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_job_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_job_location FOREIGN KEY (default_service_location_id) REFERENCES service_locations(id) ON DELETE SET NULL,
    CONSTRAINT fk_job_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_service_locations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    service_location_id INT NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_service_location (project_id,service_location_id),
    INDEX idx_project_service_default (project_id,is_default),
    CONSTRAINT fk_project_service_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_service_location FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_migration_issues (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    job_code VARCHAR(64) NOT NULL,
    issue_code VARCHAR(80) NOT NULL,
    details JSON NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_job_migration_issue (client_id,job_code,issue_code),
    CONSTRAINT fk_job_migration_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_quote_job := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quotes' AND column_name='job_id');
SET @sql := IF(@has_quote_job=0, 'ALTER TABLE quotes ADD COLUMN job_id INT NULL AFTER project_id, ADD COLUMN service_location_id INT NULL AFTER job_id, ADD COLUMN revision_number INT NOT NULL DEFAULT 1, ADD COLUMN last_sent_revision INT NULL, ADD COLUMN revision_updated_at DATETIME NULL, ADD INDEX idx_quotes_job (job_id), ADD INDEX idx_quotes_service_location (service_location_id), ADD CONSTRAINT fk_quotes_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL, ADD CONSTRAINT fk_quotes_service_location FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_contract_job := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='contracts' AND column_name='job_id');
SET @sql := IF(@has_contract_job=0, 'ALTER TABLE contracts ADD COLUMN job_id INT NULL AFTER project_id, ADD COLUMN service_location_id INT NULL AFTER job_id, ADD COLUMN revision_number INT NOT NULL DEFAULT 1, ADD COLUMN last_sent_revision INT NULL, ADD COLUMN revision_updated_at DATETIME NULL, ADD COLUMN signed_revision_number INT NULL, ADD COLUMN signed_pdf_sha256 CHAR(64) NULL, ADD INDEX idx_contracts_job (job_id), ADD INDEX idx_contracts_service_location (service_location_id), ADD CONSTRAINT fk_contracts_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL, ADD CONSTRAINT fk_contracts_service_location FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_invoice_job := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='job_id');
SET @sql := IF(@has_invoice_job=0, 'ALTER TABLE invoices ADD COLUMN job_id INT NULL AFTER project_id, ADD COLUMN service_location_id INT NULL AFTER job_id, ADD COLUMN revision_number INT NOT NULL DEFAULT 1, ADD COLUMN last_sent_revision INT NULL, ADD COLUMN revision_updated_at DATETIME NULL, ADD COLUMN credit_due DECIMAL(12,2) NOT NULL DEFAULT 0, ADD INDEX idx_invoices_job (job_id), ADD INDEX idx_invoices_service_location (service_location_id), ADD CONSTRAINT fk_invoices_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL, ADD CONSTRAINT fk_invoices_service_location FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS document_revisions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_type ENUM('quote','contract','invoice') NOT NULL,
    document_id INT NOT NULL,
    revision_number INT NOT NULL,
    snapshot JSON NOT NULL,
    content_hash CHAR(64) NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_document_revision (document_type,document_id,revision_number),
    INDEX idx_document_revision_created (created_at),
    CONSTRAINT fk_document_revision_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_deliveries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_type ENUM('quote','contract','invoice') NOT NULL,
    document_id INT NOT NULL,
    revision_number INT NOT NULL,
    email_delivery_id BIGINT NULL,
    recipient VARCHAR(255) NULL,
    delivered_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_document_delivery (document_type,document_id,revision_number),
    CONSTRAINT fk_document_delivery_email FOREIGN KEY (email_delivery_id) REFERENCES email_delivery_log(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_address_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_type ENUM('quote','contract','invoice') NOT NULL,
    document_id INT NOT NULL,
    revision_number INT NOT NULL,
    purpose ENUM('billing','service') NOT NULL,
    address_id INT NULL,
    snapshot JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_document_address_snapshot (document_type,document_id,revision_number,purpose),
    CONSTRAINT fk_document_snapshot_address FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_adjustments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    adjustment_type ENUM('charge','credit') NOT NULL,
    label VARCHAR(255) NOT NULL,
    description TEXT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    revision_number INT NOT NULL DEFAULT 1,
    superseded_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoice_adjustment_invoice (invoice_id),
    INDEX idx_invoice_adjustment_current (invoice_id,superseded_at,revision_number),
    CONSTRAINT fk_invoice_adjustment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_adjustment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO invoice_adjustments (invoice_id,adjustment_type,label,description,quantity,unit_price,amount,revision_number,created_by,created_at)
SELECT ii.invoice_id,IF(ii.unit_price<0,'credit','charge'),COALESCE(NULLIF(ii.item,''),'Invoice adjustment'),ii.description,
       ii.quantity,ii.unit_price,ii.line_total,1,i.created_by,ii.created_at
FROM invoice_items ii JOIN invoices i ON i.id=ii.invoice_id
WHERE ii.is_extra_charge=1
  AND NOT EXISTS (SELECT 1 FROM invoice_adjustments a WHERE a.invoice_id=ii.invoice_id AND a.label=COALESCE(NULLIF(ii.item,''),'Invoice adjustment') AND a.created_at=ii.created_at);

CREATE TABLE IF NOT EXISTS route_estimate_cache (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_mileage_origin_id INT NOT NULL,
    service_location_id INT NOT NULL,
    travel_mode ENUM('DRIVE') NOT NULL DEFAULT 'DRIVE',
    distance_miles DECIMAL(10,3) NOT NULL,
    duration_seconds INT NULL,
    provider ENUM('google_routes') NOT NULL DEFAULT 'google_routes',
    attribution VARCHAR(100) NOT NULL DEFAULT 'Google Maps',
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_route_estimate_pair (user_mileage_origin_id,service_location_id,travel_mode),
    INDEX idx_route_estimate_expiry (expires_at),
    CONSTRAINT fk_route_estimate_origin FOREIGN KEY (user_mileage_origin_id) REFERENCES user_mileage_origins(id) ON DELETE CASCADE,
    CONSTRAINT fk_route_estimate_destination FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_entries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NULL,
    job_id INT NULL,
    service_location_id INT NULL,
    title VARCHAR(255) NOT NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 1,
    timezone VARCHAR(80) NOT NULL DEFAULT 'America/Chicago',
    status ENUM('planned','confirmed','completed','cancelled') NOT NULL DEFAULT 'planned',
    source_type ENUM('project','job','manual') NOT NULL,
    source_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_schedule_range (starts_at,ends_at),
    INDEX idx_schedule_project (project_id),
    INDEX idx_schedule_job (job_id),
    UNIQUE KEY uq_schedule_source (source_type,source_id),
    CONSTRAINT fk_schedule_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_schedule_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_schedule_location FOREIGN KEY (service_location_id) REFERENCES service_locations(id) ON DELETE SET NULL,
    CONSTRAINT fk_schedule_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve authoritative user-entered legacy addresses and attach them as defaults.
INSERT INTO addresses (label,address_line1,address_line2,city,state,postal_code,country,source,legacy_key)
SELECT CONCAT(c.name,' billing'),c.address_line1,c.address_line2,c.city,c.state,c.postal_code,c.country,'manual',CONCAT('client:',c.id,':billing')
FROM clients c
WHERE COALESCE(c.address_line1,c.address_line2,c.city,c.state,c.postal_code,'')<>''
ON DUPLICATE KEY UPDATE legacy_key=VALUES(legacy_key);
INSERT INTO address_assignments (address_id,entity_type,entity_id,purpose,is_default)
SELECT a.id,'client',c.id,'billing',1 FROM clients c JOIN addresses a ON a.legacy_key=CONCAT('client:',c.id,':billing')
ON DUPLICATE KEY UPDATE is_default=1;

INSERT INTO addresses (label,address_line1,address_line2,city,state,postal_code,country,source,legacy_key)
SELECT CONCAT(o.name,' billing'),o.address_line1,o.address_line2,o.city,o.state,o.postal_code,o.country,'manual',CONCAT('organization:',o.id,':billing')
FROM organizations o
WHERE COALESCE(o.address_line1,o.address_line2,o.city,o.state,o.postal_code,'')<>''
ON DUPLICATE KEY UPDATE legacy_key=VALUES(legacy_key);
INSERT INTO address_assignments (address_id,entity_type,entity_id,purpose,is_default)
SELECT a.id,'organization',o.id,'billing',1 FROM organizations o JOIN addresses a ON a.legacy_key=CONCAT('organization:',o.id,':billing')
ON DUPLICATE KEY UPDATE is_default=1;

INSERT INTO addresses (label,address_line1,address_line2,city,state,postal_code,country,source,legacy_key)
SELECT s.name,s.address_line1,s.address_line2,s.city,s.state,s.postal_code,s.country,'manual',CONCAT('service_location:',s.id)
FROM service_locations s
WHERE COALESCE(s.address_line1,s.address_line2,s.city,s.state,s.postal_code,'')<>''
ON DUPLICATE KEY UPDATE legacy_key=VALUES(legacy_key);
UPDATE service_locations s JOIN addresses a ON a.legacy_key=CONCAT('service_location:',s.id)
SET s.address_id=COALESCE(s.address_id,a.id);

-- Backfill first-class jobs and propagate their identity to all linked documents.
INSERT INTO jobs (client_id,organization_id,project_id,job_code,created_by,created_at)
SELECT x.client_id,MAX(x.organization_id),IF(COUNT(DISTINCT x.project_id)=1,MAX(x.project_id),NULL),x.project_code,MAX(x.created_by),MIN(x.created_at)
FROM (
    SELECT client_id,organization_id,project_id,project_code,created_by,created_at FROM quotes WHERE project_code IS NOT NULL AND project_code<>''
    UNION ALL SELECT client_id,organization_id,project_id,project_code,created_by,created_at FROM contracts WHERE project_code IS NOT NULL AND project_code<>''
    UNION ALL SELECT client_id,organization_id,project_id,project_code,created_by,created_at FROM invoices WHERE project_code IS NOT NULL AND project_code<>''
) x
GROUP BY x.client_id,x.project_code
ON DUPLICATE KEY UPDATE organization_id=COALESCE(jobs.organization_id,VALUES(organization_id)),created_at=LEAST(jobs.created_at,VALUES(created_at));

INSERT INTO job_migration_issues (client_id,job_code,issue_code,details)
SELECT x.client_id,x.project_code,'conflicting_projects',JSON_OBJECT('project_count',COUNT(DISTINCT x.project_id))
FROM (
    SELECT client_id,project_id,project_code FROM quotes WHERE project_code IS NOT NULL AND project_id IS NOT NULL
    UNION ALL SELECT client_id,project_id,project_code FROM contracts WHERE project_code IS NOT NULL AND project_id IS NOT NULL
    UNION ALL SELECT client_id,project_id,project_code FROM invoices WHERE project_code IS NOT NULL AND project_id IS NOT NULL
) x GROUP BY x.client_id,x.project_code HAVING COUNT(DISTINCT x.project_id)>1
ON DUPLICATE KEY UPDATE details=VALUES(details);

UPDATE quotes q JOIN jobs j ON j.client_id=q.client_id AND j.job_code=q.project_code SET q.job_id=j.id,q.project_id=COALESCE(j.project_id,q.project_id) WHERE q.job_id IS NULL;
UPDATE contracts c JOIN jobs j ON j.client_id=c.client_id AND j.job_code=c.project_code SET c.job_id=j.id,c.project_id=COALESCE(j.project_id,c.project_id) WHERE c.job_id IS NULL;
UPDATE invoices i JOIN jobs j ON j.client_id=i.client_id AND j.job_code=i.project_code SET i.job_id=j.id,i.project_id=COALESCE(j.project_id,i.project_id) WHERE i.job_id IS NULL;

DELETE pd FROM project_documents pd JOIN quotes q ON pd.document_type='quote' AND pd.document_id=q.id JOIN jobs j ON j.id=q.job_id WHERE j.project_id IS NOT NULL AND pd.project_id<>j.project_id;
INSERT INTO project_documents (project_id,document_type,document_id)
SELECT j.project_id,'quote',q.id FROM quotes q JOIN jobs j ON j.id=q.job_id WHERE j.project_id IS NOT NULL
AND NOT EXISTS (SELECT 1 FROM project_documents pd WHERE pd.document_type='quote' AND pd.document_id=q.id);
DELETE pd FROM project_documents pd JOIN contracts c ON pd.document_type='contract' AND pd.document_id=c.id JOIN jobs j ON j.id=c.job_id WHERE j.project_id IS NOT NULL AND pd.project_id<>j.project_id;
INSERT INTO project_documents (project_id,document_type,document_id)
SELECT j.project_id,'contract',c.id FROM contracts c JOIN jobs j ON j.id=c.job_id WHERE j.project_id IS NOT NULL
AND NOT EXISTS (SELECT 1 FROM project_documents pd WHERE pd.document_type='contract' AND pd.document_id=c.id);
DELETE pd FROM project_documents pd JOIN invoices i ON pd.document_type='invoice' AND pd.document_id=i.id JOIN jobs j ON j.id=i.job_id WHERE j.project_id IS NOT NULL AND pd.project_id<>j.project_id;
INSERT INTO project_documents (project_id,document_type,document_id)
SELECT j.project_id,'invoice',i.id FROM invoices i JOIN jobs j ON j.id=i.job_id WHERE j.project_id IS NOT NULL
AND NOT EXISTS (SELECT 1 FROM project_documents pd WHERE pd.document_type='invoice' AND pd.document_id=i.id);

-- Baseline revision snapshots are deliberately concise; later saves capture full rows and items.
INSERT INTO document_revisions (document_type,document_id,revision_number,snapshot,content_hash,created_by,created_at)
SELECT 'quote',q.id,1,JSON_OBJECT('status',q.status,'client_id',q.client_id,'project_id',q.project_id,'job_id',q.job_id,'subtotal',q.subtotal,'total',q.total),
       SHA2(CONCAT_WS('|','quote',q.id,q.status,q.client_id,COALESCE(q.project_id,0),q.subtotal,q.total),256),q.created_by,q.created_at
FROM quotes q ON DUPLICATE KEY UPDATE document_id=VALUES(document_id);
INSERT INTO document_revisions (document_type,document_id,revision_number,snapshot,content_hash,created_by,created_at)
SELECT 'contract',c.id,1,JSON_OBJECT('status',c.status,'client_id',c.client_id,'project_id',c.project_id,'job_id',c.job_id,'subtotal',c.subtotal,'total',c.total,'signed_at',c.signed_at),
       SHA2(CONCAT_WS('|','contract',c.id,c.status,c.client_id,COALESCE(c.project_id,0),c.subtotal,c.total,COALESCE(c.signed_at,'')),256),c.created_by,c.created_at
FROM contracts c ON DUPLICATE KEY UPDATE document_id=VALUES(document_id);
INSERT INTO document_revisions (document_type,document_id,revision_number,snapshot,content_hash,created_by,created_at)
SELECT 'invoice',i.id,1,JSON_OBJECT('status',i.status,'client_id',i.client_id,'project_id',i.project_id,'job_id',i.job_id,'subtotal',i.subtotal,'total',i.total,'amount_paid',i.amount_paid),
       SHA2(CONCAT_WS('|','invoice',i.id,i.status,i.client_id,COALESCE(i.project_id,0),i.subtotal,i.total,i.amount_paid),256),i.created_by,i.created_at
FROM invoices i ON DUPLICATE KEY UPDATE document_id=VALUES(document_id);

INSERT INTO document_address_snapshots (document_type,document_id,revision_number,purpose,address_id,snapshot)
SELECT x.document_type,x.document_id,1,x.purpose,x.address_id,x.snapshot FROM (
  SELECT 'quote' document_type,q.id document_id,'billing' purpose,a.id address_id,
         JSON_OBJECT('label',a.label,'address_line1',a.address_line1,'address_line2',a.address_line2,'city',a.city,'state',a.state,'postal_code',a.postal_code,'country',a.country) snapshot
  FROM quotes q JOIN address_assignments aa ON aa.entity_type='client' AND aa.entity_id=q.client_id AND aa.purpose='billing' AND aa.is_default=1 JOIN addresses a ON a.id=aa.address_id
  UNION ALL SELECT 'contract',c.id,'billing',a.id,JSON_OBJECT('label',a.label,'address_line1',a.address_line1,'address_line2',a.address_line2,'city',a.city,'state',a.state,'postal_code',a.postal_code,'country',a.country)
  FROM contracts c JOIN address_assignments aa ON aa.entity_type='client' AND aa.entity_id=c.client_id AND aa.purpose='billing' AND aa.is_default=1 JOIN addresses a ON a.id=aa.address_id
  UNION ALL SELECT 'invoice',i.id,'billing',a.id,JSON_OBJECT('label',a.label,'address_line1',a.address_line1,'address_line2',a.address_line2,'city',a.city,'state',a.state,'postal_code',a.postal_code,'country',a.country)
  FROM invoices i JOIN address_assignments aa ON aa.entity_type='client' AND aa.entity_id=i.client_id AND aa.purpose='billing' AND aa.is_default=1 JOIN addresses a ON a.id=aa.address_id
  UNION ALL SELECT 'quote',q.id,'service',a.id,JSON_OBJECT('label',a.label,'address_line1',a.address_line1,'address_line2',a.address_line2,'city',a.city,'state',a.state,'postal_code',a.postal_code,'country',a.country)
  FROM quotes q JOIN service_locations s ON s.id=q.service_location_id JOIN addresses a ON a.id=s.address_id
  UNION ALL SELECT 'contract',c.id,'service',a.id,JSON_OBJECT('label',a.label,'address_line1',a.address_line1,'address_line2',a.address_line2,'city',a.city,'state',a.state,'postal_code',a.postal_code,'country',a.country)
  FROM contracts c JOIN service_locations s ON s.id=c.service_location_id JOIN addresses a ON a.id=s.address_id
  UNION ALL SELECT 'invoice',i.id,'service',a.id,JSON_OBJECT('label',a.label,'address_line1',a.address_line1,'address_line2',a.address_line2,'city',a.city,'state',a.state,'postal_code',a.postal_code,'country',a.country)
  FROM invoices i JOIN service_locations s ON s.id=i.service_location_id JOIN addresses a ON a.id=s.address_id
) x ON DUPLICATE KEY UPDATE address_id=VALUES(address_id),snapshot=VALUES(snapshot);

UPDATE contracts SET signed_at=COALESCE(signed_at,updated_at),signed_revision_number=COALESCE(signed_revision_number,revision_number)
WHERE signed_pdf_path IS NOT NULL AND signed_pdf_path<>'';
UPDATE quotes SET last_sent_revision=1 WHERE status='pending' AND last_sent_revision IS NULL;
UPDATE contracts SET last_sent_revision=1 WHERE status='pending' AND last_sent_revision IS NULL;
UPDATE invoices SET last_sent_revision=1 WHERE status<>'draft' AND last_sent_revision IS NULL
  AND (sent_at IS NOT NULL OR EXISTS (SELECT 1 FROM public_links p WHERE p.document_type='invoice' AND p.document_id=invoices.id));
