-- Project Alpha 0.5.0 - Database Baseline
-- Destructive reset baseline. Databases from 0.4.x and earlier are not
-- upgraded in place; initialize this file only against an empty database.
-- ============================================================================

-- ============================================================================
-- MODULE 001: Authentication & Identity
-- ============================================================================

-- USERS
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    username VARCHAR(50) NULL,
    role ENUM('admin', 'owner', 'staff', 'member', 'user') NOT NULL DEFAULT 'member',
    force_password_reset TINYINT(1) NOT NULL DEFAULT 0,
    document_sender_enabled TINYINT(1) NOT NULL DEFAULT 0,
    document_sender_name VARCHAR(255) NULL,
    document_sender_company VARCHAR(255) NULL,
    document_sender_address_line1 VARCHAR(255) NULL,
    document_sender_address_line2 VARCHAR(255) NULL,
    document_sender_city VARCHAR(120) NULL,
    document_sender_state VARCHAR(120) NULL,
    document_sender_postal VARCHAR(40) NULL,
    document_sender_country VARCHAR(120) NULL,
    document_sender_phone VARCHAR(80) NULL,
    document_sender_email VARCHAR(255) NULL,
    is_disabled TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    tos_accepted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PASSWORD RESETS
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT(1) NOT NULL DEFAULT 0,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_resets_user (user_id),
    INDEX idx_resets_token (token),
    CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LOGIN ATTEMPTS
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    email VARCHAR(255) NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_ip (ip),
    INDEX idx_attempts_email (email),
    INDEX idx_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LOGIN 2FA ATTEMPTS
CREATE TABLE IF NOT EXISTS login_2fa_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    success TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_2fa_attempts_user (user_id),
    INDEX idx_2fa_attempts_ip (ip),
    INDEX idx_2fa_attempts_time (attempted_at),
    CONSTRAINT fk_2fa_attempts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USER 2FA SETTINGS
CREATE TABLE IF NOT EXISTS user_2fa (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    secret VARCHAR(255) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    backup_codes TEXT NULL,
    enabled_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_2fa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TRUSTED DEVICES
CREATE TABLE IF NOT EXISTS trusted_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    device_token VARCHAR(64) NOT NULL,
    device_name VARCHAR(255) NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent_hash VARCHAR(64) NOT NULL,
    last_verified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    INDEX idx_trusted_devices_user (user_id),
    INDEX idx_trusted_devices_token (device_token),
    INDEX idx_trusted_devices_expires (expires_at),
    CONSTRAINT fk_trusted_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TRUSTED IPs
CREATE TABLE IF NOT EXISTS trusted_ips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    description VARCHAR(255) NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_trusted_ips_address (ip_address),
    CONSTRAINT fk_trusted_ips_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MODULE 002: Organizations, Roles, API Keys & Webhooks
-- ============================================================================

-- ORGANIZATIONS
CREATE TABLE IF NOT EXISTS organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    notes TEXT NULL,
    address_line1 VARCHAR(255) NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(100) NULL,
    postal_code VARCHAR(32) NULL,
    country VARCHAR(100) NULL,
    tax_exempt_file VARCHAR(255) NULL,
    tax_exempt_uploaded_at TIMESTAMP NULL,
    link_strategy ENUM('department_links_only','overall_folder','shared_folder') NOT NULL DEFAULT 'overall_folder',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_organizations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ROLES
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    organization_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_role_name_org (name, organization_id),
    INDEX idx_roles_org (organization_id),
    INDEX idx_roles_system (is_system),
    CONSTRAINT fk_roles_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ROLE PERMISSIONS
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission VARCHAR(80) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_role_permission (role_id, permission),
    INDEX idx_rp_role (role_id),
    INDEX idx_rp_permission (permission),
    CONSTRAINT fk_rp_role
        FOREIGN KEY (role_id) REFERENCES roles(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USER PERMISSION OVERRIDES
CREATE TABLE IF NOT EXISTS user_permissions_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    organization_id INT NULL,
    permission VARCHAR(80) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_org_permission (user_id, organization_id, permission),
    INDEX idx_upo_user (user_id),
    INDEX idx_upo_permission (permission),
    CONSTRAINT fk_upo_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_upo_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed system roles
INSERT INTO roles (id, name, description, is_system, organization_id) VALUES
    (1, 'admin', 'Super-admin bypass; manages app, all orgs, all data.', 1, NULL),
    (2, 'owner', 'Full access within assigned orgs.', 1, NULL),
    (3, 'staff', 'Operational access within assigned orgs; no settings/users/billing.', 1, NULL),
    (4, 'member', 'Read-mostly within assigned orgs; record-level scoping.', 1, NULL)
ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    is_system = VALUES(is_system);

-- Seed system role permissions
INSERT INTO role_permissions (role_id, permission, allowed) VALUES
    (1, '*', 1),
    (1, 'payments.view', 1), (1, 'payments.create', 1),
    (2, 'quotes.*', 1), (2, 'contracts.*', 1), (2, 'invoices.*', 1),
    (2, 'clients.*', 1), (2, 'projects.*', 1), (2, 'jobs.*', 1),
    (2, 'financial.view', 1), (2, 'financial.manage', 1), (2, 'financial.export', 1), (2, 'financial.audit', 1),
    (2, 'reports.view', 1), (2, 'settings.view', 1), (2, 'settings.manage', 1),
    (2, 'users.view', 1), (2, 'users.manage', 1), (2, 'users.reset_password', 1),
    (2, 'api_keys.*', 1), (2, 'billing.*', 1), (2, 'organizations.*', 1),
    (2, 'public_links.*', 1), (2, 'time_tracking.*', 1), (2, 'profile.*', 1),
    (2, 'payments.view', 1), (2, 'payments.create', 1),
    (3, 'quotes.*', 1), (3, 'contracts.*', 1), (3, 'invoices.*', 1),
    (3, 'clients.*', 1), (3, 'projects.*', 1), (3, 'jobs.*', 1),
    (3, 'organizations.*', 1), (3, 'public_links.*', 1),
    (3, 'payments.view', 1), (3, 'payments.create', 1),
    (3, 'financial.view', 0), (3, 'financial.manage', 0), (3, 'financial.export', 0), (3, 'financial.audit', 0),
    (3, 'reports.view', 0),
    (3, 'settings.view', 0), (3, 'settings.manage', 0),
    (3, 'users.view', 0), (3, 'users.manage', 0), (3, 'users.reset_password', 0),
    (3, 'api_keys.*', 0), (3, 'billing.*', 0),
    (3, 'time_tracking.*', 1), (3, 'profile.*', 1), (3, '2fa.manage', 0),
    (4, 'quotes.view', 1), (4, 'quotes.create', 1), (4, 'quotes.edit', 1), (4, 'quotes.send', 1), (4, 'quotes.approve', 1), (4, 'quotes.reject', 1),
    (4, 'contracts.view', 1), (4, 'contracts.create', 1), (4, 'contracts.edit', 1), (4, 'contracts.sign', 1), (4, 'contracts.complete', 1), (4, 'contracts.void', 1), (4, 'contracts.send', 1),
    (4, 'invoices.view', 1), (4, 'invoices.create', 1), (4, 'invoices.edit', 1), (4, 'invoices.void', 1), (4, 'invoices.mark_paid', 1), (4, 'invoices.send', 1),
    (4, 'payments.view', 1), (4, 'payments.create', 1),
    (4, 'clients.view', 1), (4, 'clients.create', 1), (4, 'clients.edit', 1), (4, 'clients.onboarding', 1), (4, 'clients.delete', 1), (4, 'clients.purge', 1), (4, 'clients.restore', 1),
    (4, 'projects.view', 1), (4, 'projects.create', 1), (4, 'projects.edit', 1), (4, 'projects.delete', 1), (4, 'projects.search', 1),
    (4, 'jobs.view', 1), (4, 'jobs.edit', 1), (4, 'jobs.search', 1),
    (4, 'organizations.view', 1), (4, 'organizations.manage', 1),
    (4, 'public_links.view', 1), (4, 'public_links.create', 1), (4, 'public_links.revoke', 1), (4, 'public_links.manage', 1),
    (4, 'time_tracking.view', 0), (4, 'time_tracking.manage', 0),
    (4, 'reports.view', 0),
    (4, 'financial.view', 0), (4, 'financial.manage', 0), (4, 'financial.export', 0), (4, 'financial.audit', 0),
    (4, 'billing.view', 0), (4, 'billing.manage', 0),
    (4, 'users.view', 0), (4, 'users.manage', 0), (4, 'users.reset_password', 0), (4, 'users.delete', 0),
    (4, 'api_keys.view', 0), (4, 'api_keys.manage', 0),
    (4, 'settings.view', 0), (4, 'settings.manage', 0),
    (4, '2fa.manage', 0),
    (4, 'profile.view', 1), (4, 'profile.edit', 1)
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

-- Seed void permission keys for system roles (owner/staff/member allowed)
INSERT INTO role_permissions (role_id, permission, allowed)
SELECT id, 'contracts.void', CASE WHEN name IN ('owner','staff','member') THEN 1 ELSE 0 END FROM roles WHERE is_system = 1
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

INSERT INTO role_permissions (role_id, permission, allowed)
SELECT id, 'invoices.void', CASE WHEN name IN ('owner','staff','member') THEN 1 ELSE 0 END FROM roles WHERE is_system = 1
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

INSERT INTO role_permissions (role_id, permission, allowed)
SELECT id, 'clients.onboarding', CASE WHEN name IN ('admin','owner','staff','member') THEN 1 ELSE 0 END FROM roles WHERE is_system = 1
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

-- API KEYS
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    name VARCHAR(255) NOT NULL,
    key_prefix VARCHAR(32) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    scopes VARCHAR(1024) NULL,
    allowed_ips TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL,
    revoked_at TIMESTAMP NULL,
    UNIQUE KEY uq_key_hash (key_hash),
    INDEX idx_api_keys_prefix (key_prefix),
    INDEX idx_api_keys_revoked (revoked_at),
    INDEX idx_api_keys_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API USAGE
CREATE TABLE IF NOT EXISTS api_usage (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT NOT NULL,
    used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_usage_key_time (api_key_id, used_at),
    CONSTRAINT fk_api_usage_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ACTIVITY LOG
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    document_type VARCHAR(20) NULL,
    document_id INT NULL,
    client_id INT NULL,
    description TEXT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_type (event_type),
    INDEX idx_activity_doc (document_type, document_id),
    INDEX idx_activity_client (client_id),
    INDEX idx_activity_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- WEBHOOKS
CREATE TABLE IF NOT EXISTS webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    name VARCHAR(100) NOT NULL,
    url VARCHAR(500) NOT NULL,
    events JSON NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    secret VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_webhooks_active (is_active),
    INDEX idx_webhooks_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- WEBHOOK DELIVERIES
-- ============================================================================
-- MODULE 003: Projects & Clients
-- ============================================================================

-- CLIENTS
CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    address_line1 VARCHAR(255) NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(2) NULL,
    postal_code VARCHAR(20) NULL,
    country VARCHAR(100) NULL DEFAULT 'US',
    organization_id INT NULL,
    client_type ENUM('unknown','business','consumer') NOT NULL DEFAULT 'unknown',
    created_by INT NULL,
    config JSON NULL,
    stripe_customer_id VARCHAR(255) NULL,
    stripe_payment_method_id VARCHAR(255) NULL,
    auto_pay_enabled TINYINT(1) NOT NULL DEFAULT 0,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    archive_payload JSON NULL,
    notes TEXT NULL,
    custom_fields JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_clients_name (name),
    INDEX idx_clients_email (email),
    INDEX idx_clients_org (organization_id),
    INDEX idx_clients_stripe_customer (stripe_customer_id),
    INDEX idx_clients_archived (archived),
    INDEX idx_clients_deleted (deleted_at),
    CONSTRAINT fk_clients_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CLIENT ONBOARDING
CREATE TABLE IF NOT EXISTS client_onboarding_invitations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    target_organization_id INT NULL,
    client_id INT NULL,
    invited_email VARCHAR(255) NULL,
    token_hash CHAR(64) NOT NULL,
    token_enc TEXT NULL,
    status ENUM('pending','verified','submitted','approved','rejected','revoked','expired') NOT NULL DEFAULT 'pending',
    expires_at DATETIME NOT NULL,
    verification_code_hash VARCHAR(255) NULL,
    code_expires_at DATETIME NULL,
    verification_attempts SMALLINT NOT NULL DEFAULT 0,
    last_code_sent_at DATETIME NULL,
    email_verified_at DATETIME NULL,
    consumed_at DATETIME NULL,
    sent_at DATETIME NULL,
    notify_on_submit TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_onboarding_token (token_hash),
    INDEX idx_client_onboarding_owner (organization_id,status,created_at),
    INDEX idx_client_onboarding_email (invited_email),
    CONSTRAINT fk_client_onboarding_owner FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_onboarding_target_org FOREIGN KEY (target_organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_onboarding_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_onboarding_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_onboarding_submissions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invitation_id BIGINT NOT NULL,
    proposed_data JSON NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_notes VARCHAR(1000) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_onboarding_submission (invitation_id),
    INDEX idx_client_onboarding_review (status,created_at),
    CONSTRAINT fk_client_onboarding_submission_invite FOREIGN KEY (invitation_id) REFERENCES client_onboarding_invitations(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_onboarding_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PROJECTS
CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NULL,
    parent_id INT NULL,
    organization_id INT NULL,
    department_id INT NULL,
    created_by INT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status ENUM('not_started', 'active', 'overdue', 'completed', 'cancelled') NOT NULL DEFAULT 'not_started',
    invoice_billing_period ENUM('per_invoice','monthly') NOT NULL DEFAULT 'monthly',
    invoice_net_terms_days INT NULL,
    project_invoice_auto_email TINYINT(1) NOT NULL DEFAULT 1,
    public_project_enabled TINYINT(1) NOT NULL DEFAULT 0,
    public_project_token VARCHAR(64) NULL,
    public_project_require_password TINYINT(1) NOT NULL DEFAULT 0,
    public_project_password_hash VARCHAR(255) NULL,
    public_project_can_view_documents TINYINT(1) NOT NULL DEFAULT 1,
    public_project_can_view_invoices TINYINT(1) NOT NULL DEFAULT 1,
    public_project_can_upload TINYINT(1) NOT NULL DEFAULT 0,
    public_project_can_request_changes TINYINT(1) NOT NULL DEFAULT 0,
    start_date DATE NULL,
    end_date DATE NULL,
    estimated_start DATE NULL,
    estimated_end DATE NULL,
    budget DECIMAL(12, 2) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_projects_client (client_id),
    INDEX idx_projects_org (organization_id),
    INDEX idx_projects_department (department_id),
    INDEX idx_projects_status (status),
    INDEX idx_projects_parent (parent_id),
    UNIQUE KEY uq_projects_public_project_token (public_project_token),
    CONSTRAINT fk_projects_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_projects_parent FOREIGN KEY (parent_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_projects_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ORGANIZATION DEPARTMENTS
CREATE TABLE IF NOT EXISTS organization_departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    folder_name VARCHAR(150) NULL,
    folder_aliases JSON NULL,
    resolver_mode ENUM('auto_attach','review','manual_only','excluded') NOT NULL DEFAULT 'manual_only',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_org_department_name (organization_id, name),
    INDEX idx_org_departments_org (organization_id),
    CONSTRAINT fk_org_departments_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organization_department_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    client_id INT NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'contact',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_department_contact (department_id, client_id),
    INDEX idx_department_contacts_department (department_id),
    INDEX idx_department_contacts_client (client_id),
    CONSTRAINT fk_department_contacts_department FOREIGN KEY (department_id) REFERENCES organization_departments(id) ON DELETE CASCADE,
    CONSTRAINT fk_department_contacts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PROJECT META
CREATE TABLE IF NOT EXISTS project_meta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NULL,
    project_code VARCHAR(64) NULL,
    client_id INT NULL,
    meta_key VARCHAR(100) NULL,
    meta_value TEXT NULL,
    notes TEXT NULL,
    terms TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_meta_key (project_id, meta_key),
    UNIQUE KEY uq_project_meta_code (project_code),
    INDEX idx_project_meta_client (client_id),
    CONSTRAINT fk_project_meta_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_meta_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PROJECT COUNTERS
CREATE TABLE IF NOT EXISTS project_counters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    project_id INT NULL,
    counter_type VARCHAR(50) NOT NULL,
    counter_value INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_counter (organization_id, project_id, counter_type),
    INDEX idx_counters_org (organization_id),
    INDEX idx_counters_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PROJECT DOCUMENTS
CREATE TABLE IF NOT EXISTS project_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    document_type ENUM('quote', 'contract', 'invoice', 'recurring_invoice', 'receipt', 'form', 'other') NOT NULL DEFAULT 'other',
    document_id INT NOT NULL,
    file_path VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_project_docs_project (project_id),
    INDEX idx_project_docs_type (document_type, document_id),
    CONSTRAINT fk_project_docs_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PROJECT FILE UPLOADS
CREATE TABLE IF NOT EXISTS project_file_folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    client_visible TINYINT(1) NOT NULL DEFAULT 0,
    client_upload_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_file_folder_name (project_id, name),
    INDEX idx_project_file_folders_project (project_id),
    INDEX idx_project_file_folders_created_by (created_by),
    CONSTRAINT fk_project_file_folders_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_file_folders_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    folder_id INT NULL,
    original_name VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(190) NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    client_visible TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_project_files_project (project_id),
    INDEX idx_project_files_folder (folder_id),
    INDEX idx_project_files_uploaded_by (uploaded_by),
    CONSTRAINT fk_project_files_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_files_folder FOREIGN KEY (folder_id) REFERENCES project_file_folders(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_files_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_public_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    message TEXT NULL,
    file_id INT NULL,
    client_label VARCHAR(190) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_project_public_events_project (project_id),
    INDEX idx_project_public_events_type (event_type),
    CONSTRAINT fk_project_public_events_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_public_events_file FOREIGN KEY (file_id) REFERENCES project_files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PROJECT CLIENTS
CREATE TABLE IF NOT EXISTS project_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    client_id INT NOT NULL,
    department_id INT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'contact',
    is_primary_billing TINYINT(1) NOT NULL DEFAULT 0,
    send_project_invoices TINYINT(1) NOT NULL DEFAULT 1,
    can_view_invoice_links TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_client (project_id, client_id),
    INDEX idx_project_clients_project (project_id),
    INDEX idx_project_clients_client (client_id),
    INDEX idx_project_clients_department (department_id),
    CONSTRAINT fk_project_clients_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_clients_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_clients_department FOREIGN KEY (department_id) REFERENCES organization_departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PROJECT INVOICES
CREATE TABLE IF NOT EXISTS project_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    organization_id INT NULL,
    primary_client_id INT NULL,
    doc_number INT NULL,
    status ENUM('draft','sent','unpaid','partial','paid','void') NOT NULL DEFAULT 'unpaid',
    billing_period_start DATE NOT NULL,
    billing_period_end DATE NOT NULL,
    due_date DATE NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance_due DECIMAL(12,2) NOT NULL DEFAULT 0,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    finalized_at TIMESTAMP NULL DEFAULT NULL,
    finalization_source VARCHAR(50) NULL,
    stripe_session_id VARCHAR(255) NULL,
    stripe_checkout_expires_at DATETIME NULL,
    paid_at TIMESTAMP NULL DEFAULT NULL,
    generated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_invoice_period (project_id, billing_period_start, billing_period_end),
    INDEX idx_project_invoices_project (project_id),
    INDEX idx_project_invoices_status (status),
    INDEX idx_project_invoices_due (due_date),
    CONSTRAINT fk_project_invoices_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_invoices_primary_client FOREIGN KEY (primary_client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_invoice_id INT NOT NULL,
    invoice_id INT NOT NULL,
    invoice_doc_number INT NULL,
    invoice_date DATE NULL,
    invoice_due_date DATE NULL,
    invoice_status VARCHAR(50) NULL,
    invoice_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_paid_at_generation DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_due_at_generation DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_applied DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_invoice_child_invoice (invoice_id),
    INDEX idx_project_invoice_items_parent (project_invoice_id),
    INDEX idx_project_invoice_items_invoice (invoice_id),
    CONSTRAINT fk_project_invoice_items_parent FOREIGN KEY (project_invoice_id) REFERENCES project_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_invoice_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_invoice_id INT NOT NULL,
    notification_type VARCHAR(50) NOT NULL DEFAULT 'on_generate',
    email_to VARCHAR(255) NOT NULL,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_invoice_notification (project_invoice_id, notification_type, email_to),
    INDEX idx_project_invoice_notif_parent (project_invoice_id),
    CONSTRAINT fk_project_invoice_notif_parent FOREIGN KEY (project_invoice_id) REFERENCES project_invoices(id) ON DELETE CASCADE
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

-- ENTITY LINKS
CREATE TABLE IF NOT EXISTS entity_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NOT NULL,
    title VARCHAR(255) NULL,
    url VARCHAR(500) NOT NULL,
    link_type VARCHAR(50) NOT NULL DEFAULT 'manual',
    link_source ENUM('manual','resolver') NOT NULL DEFAULT 'manual',
    include_on_invoices TINYINT(1) NOT NULL DEFAULT 0,
    visibility_scope ENUM('entity_only','all_departments','selected_departments','org_contacts') NOT NULL DEFAULT 'entity_only',
    selected_department_ids JSON NULL,
    resolver_mode ENUM('auto_attach','review','manual_only','excluded') NOT NULL DEFAULT 'manual_only',
    expiration_date DATE NULL,
    is_expired TINYINT(1) NOT NULL DEFAULT 0,
    ignore_auto_generation TINYINT(1) NOT NULL DEFAULT 0,
    last_verified TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_link_entity (entity_type, entity_id),
    INDEX idx_link_type (link_type),
    INDEX idx_link_source (link_source),
    INDEX idx_link_invoice (include_on_invoices, is_expired),
    INDEX idx_link_visibility (visibility_scope),
    INDEX idx_link_expired (is_expired),
    INDEX idx_link_expiration (expiration_date),
    INDEX idx_link_ignore (ignore_auto_generation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MODULE 004: Quotes, Contracts & Invoices (Separate Tables)
-- ============================================================================

-- QUOTES
CREATE TABLE IF NOT EXISTS quotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    project_id INT NULL,
    organization_id INT NULL,
    created_by INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM('draft','pending','approved','denied','rejected','expired') NOT NULL DEFAULT 'draft',
    quote_type ENUM('regular','long_term','on_demand') NOT NULL DEFAULT 'regular',
    billing_mode ENUM('fixed','hourly') NOT NULL DEFAULT 'fixed',
    discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NULL DEFAULT NULL,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    deposit_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    start_date DATE NULL,
    end_date DATE NULL,
    billing_interval_count INT NOT NULL DEFAULT 1,
    billing_interval_unit ENUM('day','week','month','year') NOT NULL DEFAULT 'month',
    pricing_type ENUM('per_invoice','fixed_total','on_demand') NULL,
    price_per_invoice DECIMAL(12,2) NULL,
    invoice_count INT NULL,
    scope TEXT NULL,
    terms TEXT NULL,
    fulfillment_date DATE NULL,
    weather_pending TINYINT(1) NOT NULL DEFAULT 0,
    estimated_completion VARCHAR(200) NULL,
    custom_fields JSON NULL,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_quotes_client (client_id),
    INDEX idx_quotes_project (project_id),
    INDEX idx_quotes_org (organization_id),
    INDEX idx_quotes_status (status),
    INDEX idx_quotes_type (quote_type),
    INDEX idx_quotes_doc_number (doc_number),
    INDEX idx_quotes_project_code (project_code),
    CONSTRAINT fk_quotes_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_quotes_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_quotes_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- QUOTE ITEMS
CREATE TABLE IF NOT EXISTS quote_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NOT NULL,
    item VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    billing_unit ENUM('each','hour') NOT NULL DEFAULT 'each',
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_quote_items_quote (quote_id),
    INDEX idx_quote_items_sort (sort_order),
    CONSTRAINT fk_quote_items_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CONTRACTS
CREATE TABLE IF NOT EXISTS contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NULL,
    client_id INT NOT NULL,
    project_id INT NULL,
    organization_id INT NULL,
    created_by INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM('draft','pending','active','paused','completed','cancelled','denied','void') NOT NULL DEFAULT 'pending',
    contract_type ENUM('regular','long_term','on_demand') NOT NULL DEFAULT 'regular',
    billing_mode ENUM('fixed','hourly') NOT NULL DEFAULT 'fixed',
    discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NULL DEFAULT NULL,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    deposit_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    deposit_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    start_date DATE NULL,
    end_date DATE NULL,
    billing_interval_count INT NOT NULL DEFAULT 1,
    billing_interval_unit ENUM('day','week','month','year') NOT NULL DEFAULT 'month',
    pricing_type ENUM('per_invoice','fixed_total','on_demand') NULL,
    price_per_invoice DECIMAL(12,2) NULL,
    total_invoiced DECIMAL(12,2) NOT NULL DEFAULT 0,
    next_invoice_date DATE NULL,
    billing_start_mode ENUM('on_upload','manual') NULL DEFAULT 'on_upload',
    last_invoice_date DATE NULL,
    invoice_count INT NULL,
    invoices_generated INT NOT NULL DEFAULT 0,
    invoice_generation_type ENUM('set_amount','itemized','general_writeup') NOT NULL DEFAULT 'set_amount',
    signed_pdf_path VARCHAR(255) NULL,
    signed_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    voided_at TIMESTAMP NULL,
    scheduled_date DATE NULL,
    scope TEXT NULL,
    terms TEXT NULL,
    memo TEXT NULL,
    fulfillment_date DATE NULL,
    weather_pending TINYINT(1) NOT NULL DEFAULT 0,
    estimated_completion VARCHAR(200) NULL,
    custom_fields JSON NULL,
    auto_pay_enabled TINYINT(1) NOT NULL DEFAULT 0,
    payment_method_id INT NULL,
    stripe_subscription_id VARCHAR(255) NULL,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contracts_client (client_id),
    INDEX idx_contracts_project (project_id),
    INDEX idx_contracts_org (organization_id),
    INDEX idx_contracts_status (status),
    INDEX idx_contracts_type (contract_type),
    INDEX idx_contracts_doc_number (doc_number),
    INDEX idx_contracts_project_code (project_code),
    INDEX idx_contracts_quote (quote_id),
    INDEX idx_contracts_next_invoice (next_invoice_date),
    INDEX idx_contracts_auto_pay (auto_pay_enabled),
    INDEX idx_contracts_stripe_sub (stripe_subscription_id),
    CONSTRAINT fk_contracts_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
    CONSTRAINT fk_contracts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_contracts_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_contracts_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CONTRACT ITEMS
CREATE TABLE IF NOT EXISTS contract_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    item VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    billing_unit ENUM('each','hour','mile') NOT NULL DEFAULT 'each',
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contract_items_contract (contract_id),
    INDEX idx_contract_items_sort (sort_order),
    CONSTRAINT fk_contract_items_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CONTRACT SIGNATURES
CREATE TABLE IF NOT EXISTS contract_signatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    signatory_type ENUM('client','admin','witness') NOT NULL DEFAULT 'client',
    signer_title VARCHAR(190) NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    signature_data TEXT NULL,
    signed_at TIMESTAMP NULL,
    signed_by_user_id INT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cs_contract (contract_id),
    INDEX idx_cs_contract_order (contract_id, display_order),
    INDEX idx_cs_type (signatory_type),
    CONSTRAINT fk_cs_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_cs_user FOREIGN KEY (signed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CONTRACT NOTES
-- INVOICES
CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NULL,
    quote_id INT NULL,
    client_id INT NOT NULL,
    project_id INT NULL,
    organization_id INT NULL,
    created_by INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM('draft','sent','unpaid','partial','paid','overdue','cancelled','void') NOT NULL DEFAULT 'draft',
    invoice_type ENUM('regular','long_term','on_demand') NOT NULL DEFAULT 'regular',
    billing_mode ENUM('fixed','hourly') NOT NULL DEFAULT 'fixed',
    is_deposit_invoice TINYINT(1) NOT NULL DEFAULT 0,
    parent_contract_type ENUM('contract','long_term_contract','on_demand_contract') NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance_due DECIMAL(12,2) NOT NULL DEFAULT 0,
    due_date DATE NULL,
    fulfillment_date DATE NULL,
    weather_pending TINYINT(1) NOT NULL DEFAULT 0,
    estimated_completion VARCHAR(200) NULL,
    paid_at TIMESTAMP NULL,
    voided_at TIMESTAMP NULL,
    voided_by INT NULL,
    void_reason VARCHAR(500) NULL,
    void_previous_status VARCHAR(32) NULL,
    sent_at TIMESTAMP NULL,
    finalized_at TIMESTAMP NULL,
    finalized_by INT NULL,
    finalization_source VARCHAR(50) NULL,
    collection_mode ENUM('direct','project_aggregate') NOT NULL DEFAULT 'direct',
    terms TEXT NULL,
    notes TEXT NULL,
    scope TEXT NULL,
    custom_fields JSON NULL,
    stripe_session_id VARCHAR(255) NULL,
    stripe_checkout_expires_at DATETIME NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    last_auto_pay_attempt TIMESTAMP NULL,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    generated_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoices_client (client_id),
    INDEX idx_invoices_contract (contract_id),
    INDEX idx_invoices_quote (quote_id),
    INDEX idx_invoices_project (project_id),
    INDEX idx_invoices_org (organization_id),
    INDEX idx_invoices_status (status),
    INDEX idx_invoices_type (invoice_type),
    INDEX idx_invoices_doc_number (doc_number),
    INDEX idx_invoices_project_code (project_code),
    INDEX idx_invoices_due_date (due_date),
    INDEX idx_invoices_voided_at (voided_at),
    INDEX idx_invoices_auto_pay_attempt (last_auto_pay_attempt),
    CONSTRAINT fk_invoices_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoices_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INVOICE ITEMS
CREATE TABLE IF NOT EXISTS invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    item VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    billing_unit ENUM('each','hour','mile') NOT NULL DEFAULT 'each',
    hours DECIMAL(10,2) DEFAULT NULL,
    time_entry_id INT DEFAULT NULL,
    is_extra_charge TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoice_items_invoice (invoice_id),
    INDEX idx_invoice_items_sort (sort_order),
    CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INVOICE NOTIFICATIONS
CREATE TABLE IF NOT EXISTS invoice_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    notification_type VARCHAR(50) NOT NULL DEFAULT 'reminder',
    sent_at TIMESTAMP NULL,
    email_to VARCHAR(255) NULL,
    email_subject VARCHAR(255) NULL,
    email_body TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inv_notif_invoice (invoice_id),
    INDEX idx_inv_notif_type (notification_type),
    UNIQUE INDEX uq_invoice_notification (invoice_id, notification_type),
    CONSTRAINT fk_inv_notif_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- project_invoice_items is created with the Projects module, before invoices.
-- Add its cross-module foreign key only after both tables exist, and keep init
-- safe to rerun against an existing installation.
SET @has_project_invoice_fk := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'project_invoice_items'
      AND constraint_name = 'fk_project_invoice_items_invoice'
      AND constraint_type = 'FOREIGN KEY'
);
SET @project_invoice_fk_sql := IF(
    @has_project_invoice_fk = 0,
    'ALTER TABLE project_invoice_items ADD CONSTRAINT fk_project_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE project_invoice_fk_stmt FROM @project_invoice_fk_sql;
EXECUTE project_invoice_fk_stmt;
DEALLOCATE PREPARE project_invoice_fk_stmt;

-- RECURRING INVOICES
-- RECURRING INVOICE ITEMS
-- QUOTE HISTORY
-- CONTRACT HISTORY
-- INVOICE HISTORY
-- ============================================================================
-- MODULE 005: Financial
-- ============================================================================

-- PAYMENTS
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    invoice_id INT NULL,
    project_invoice_payment_id BIGINT NULL,
    contract_id INT NULL,
    organization_id INT NULL,
    amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    disputed_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    surcharge_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    surcharge_refunded TINYINT(1) NOT NULL DEFAULT 0,
    surcharge_refund_amount DECIMAL(10,2) NULL,
    payment_method ENUM('cash', 'check', 'card', 'bank_transfer', 'stripe', 'other') NOT NULL DEFAULT 'cash',
    payment_date DATE NOT NULL,
    reference_number VARCHAR(255) NULL,
    notes TEXT NULL,
    stripe_session_id VARCHAR(255) NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    auto_pay_attempt TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('succeeded', 'failed', 'pending', 'reversed') NOT NULL DEFAULT 'succeeded',
    reversed_at TIMESTAMP NULL,
    reversed_by INT NULL,
    reversal_reason VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payments_client (client_id),
    INDEX idx_payments_invoice (invoice_id),
    INDEX idx_payments_project_payment (project_invoice_payment_id),
    INDEX idx_payments_contract (contract_id),
    INDEX idx_payments_date (payment_date),
    UNIQUE KEY uq_payments_stripe_session (stripe_session_id),
    UNIQUE KEY uq_payments_stripe_pi (stripe_payment_intent_id),
    CONSTRAINT fk_payments_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_project_payment FOREIGN KEY (project_invoice_payment_id) REFERENCES project_invoice_payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PAYMENT ALLOCATION CORRECTIONS
CREATE TABLE IF NOT EXISTS payment_corrections (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    moved_payment_id INT NULL,
    reversed_payment_id INT NULL,
    reversed_payment_ids JSON NULL,
    source_invoice_id INT NULL,
    target_invoice_id INT NULL,
    corrected_by INT NULL,
    source_voided TINYINT(1) NOT NULL DEFAULT 0,
    cleared_local_refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    processor_refund_verified_amount DECIMAL(12,2) NULL,
    reason VARCHAR(500) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment_corrections_moved (moved_payment_id),
    INDEX idx_payment_corrections_reversed (reversed_payment_id),
    INDEX idx_payment_corrections_source (source_invoice_id),
    INDEX idx_payment_corrections_target (target_invoice_id),
    INDEX idx_payment_corrections_created (created_at),
    CONSTRAINT fk_payment_correction_moved FOREIGN KEY (moved_payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_correction_reversed FOREIGN KEY (reversed_payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_correction_source FOREIGN KEY (source_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_correction_target FOREIGN KEY (target_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_correction_user FOREIGN KEY (corrected_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PAYMENT INTENTS
CREATE TABLE IF NOT EXISTS payment_intents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    stripe_payment_intent_id VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_intent_stripe (stripe_payment_intent_id),
    INDEX idx_payment_intents_invoice (invoice_id),
    CONSTRAINT fk_payment_intents_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AUTO PAY LOG
CREATE TABLE IF NOT EXISTS auto_pay_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NULL,
    invoice_id INT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('succeeded','failed','pending') NOT NULL DEFAULT 'pending',
    stripe_payment_intent_id VARCHAR(255) NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auto_pay_client (client_id),
    INDEX idx_auto_pay_invoice (invoice_id),
    INDEX idx_auto_pay_status (status),
    CONSTRAINT fk_auto_pay_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_auto_pay_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AUTO PAY BETA FOUNDATION
-- Unavailable in production. No route, UI, cron job, or enabled processor uses these tables.
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
    project_invoice_payment_id BIGINT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status VARCHAR(50) NOT NULL,
    reason VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stripe_refund_id (stripe_refund_id),
    INDEX idx_stripe_refund_payment (payment_id),
    INDEX idx_stripe_refund_project_payment (project_invoice_payment_id),
    CONSTRAINT fk_stripe_refund_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_stripe_refund_project_payment FOREIGN KEY (project_invoice_payment_id) REFERENCES project_invoice_payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_disputes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    stripe_dispute_id VARCHAR(255) NOT NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    payment_id INT NULL,
    project_invoice_payment_id BIGINT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status VARCHAR(50) NOT NULL,
    reason VARCHAR(100) NULL,
    evidence_due_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stripe_dispute_id (stripe_dispute_id),
    INDEX idx_stripe_dispute_payment (payment_id),
    INDEX idx_stripe_dispute_project_payment (project_invoice_payment_id),
    CONSTRAINT fk_stripe_dispute_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_stripe_dispute_project_payment FOREIGN KEY (project_invoice_payment_id) REFERENCES project_invoice_payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PAYMENT METHODS
CREATE TABLE IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    organization_id INT NULL,
    name VARCHAR(100) NOT NULL,
    provider VARCHAR(100) NULL,
    type ENUM('cash', 'check', 'card', 'bank_transfer', 'stripe', 'other') NOT NULL DEFAULT 'cash',
    config JSON NULL,
    last_four VARCHAR(4) NULL,
    exp_month INT NULL,
    exp_year INT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    stripe_payment_method_id VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pm_user (user_id),
    INDEX idx_pm_org (organization_id),
    INDEX idx_pm_provider (provider),
    CONSTRAINT fk_pm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TAX RATES
CREATE TABLE IF NOT EXISTS tax_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    name VARCHAR(100) NOT NULL,
    country VARCHAR(100) NULL DEFAULT 'USA',
    rate DECIMAL(5, 2) NOT NULL DEFAULT 0,
    county VARCHAR(100) NULL,
    state VARCHAR(2) NULL,
    zip_code VARCHAR(10) NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tax_org (organization_id),
    INDEX idx_tax_county (county),
    INDEX idx_tax_state (state),
    INDEX idx_tax_zip (zip_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fips_counties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    state_abbr VARCHAR(2) NOT NULL,
    county_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_fips (state_fips, county_fips)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_jurisdictions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    jurisdiction_code VARCHAR(10) DEFAULT NULL,
    jurisdiction_type ENUM('state','county','city','special') NOT NULL DEFAULT 'county',
    state_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    county_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    city_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    special_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    total_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tax_jurisdiction_state (state_fips, county_fips),
    INDEX idx_tax_jurisdiction_code (state_fips, county_fips, jurisdiction_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_boundaries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zip5_start VARCHAR(5) NOT NULL,
    zip4_start VARCHAR(4) NOT NULL,
    zip5_end VARCHAR(5) NOT NULL,
    zip4_end VARCHAR(4) NOT NULL,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    jurisdiction_code VARCHAR(10) DEFAULT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tax_boundaries_state_zip (state_fips, zip5_start),
    INDEX idx_tax_boundaries_county (state_fips, county_fips),
    INDEX idx_tax_boundaries_jurisdiction (state_fips, county_fips, jurisdiction_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_boundaries_stage (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    batch_key VARCHAR(32) NOT NULL,
    zip5_start VARCHAR(5) NOT NULL,
    zip4_start VARCHAR(4) NOT NULL,
    zip5_end VARCHAR(5) NOT NULL,
    zip4_end VARCHAR(4) NOT NULL,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    jurisdiction_code VARCHAR(10) DEFAULT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tax_boundary_stage_batch (batch_key),
    INDEX idx_tax_boundary_stage_state_zip (state_fips, zip5_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_zip_complexity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zip5 VARCHAR(5) NOT NULL,
    is_complex TINYINT(1) NOT NULL DEFAULT 0,
    reason VARCHAR(50) DEFAULT NULL,
    state_fips VARCHAR(2) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tax_zip_complexity_state_zip (state_fips, zip5),
    INDEX idx_tax_zip_complexity_zip5 (zip5),
    INDEX idx_tax_zip_complexity_state (state_fips)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_import_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_fips VARCHAR(2) NOT NULL,
    file_type ENUM('fips','rates','boundaries') NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    content_hash CHAR(64) NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    state_tax_rate DECIMAL(8,4) NULL,
    imported_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tax_import_file (state_fips, file_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_import_runs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    state_fips VARCHAR(2) NOT NULL,
    state_abbr VARCHAR(2) NOT NULL,
    status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    phase VARCHAR(80) NOT NULL DEFAULT 'starting',
    message VARCHAR(255) NULL,
    fips_rows INT NOT NULL DEFAULT 0,
    rate_rows INT NOT NULL DEFAULT 0,
    boundary_rows INT NOT NULL DEFAULT 0,
    warning_count INT NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    error_message TEXT NULL,
    INDEX idx_tax_import_runs_state (state_fips, started_at),
    INDEX idx_tax_import_runs_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ITEM LIBRARY
CREATE TABLE IF NOT EXISTS item_library (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    item_name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    category VARCHAR(100) NULL,
    sku VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_item_lib_org (organization_id),
    INDEX idx_item_lib_item_name (item_name),
    INDEX idx_item_lib_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- EXPENSE CATEGORIES (IRS Schedule C aligned)
CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    parent_id INT NULL DEFAULT NULL,
    tax_deductible TINYINT(1) NOT NULL DEFAULT 1,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    color VARCHAR(7) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_exp_cat_org (organization_id),
    INDEX idx_exp_cat_parent (parent_id),
    CONSTRAINT fk_exp_cat_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_cat_parent FOREIGN KEY (parent_id) REFERENCES expense_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DEFAULT ORGANIZATION (seed early so financial module FKs resolve)
INSERT INTO organizations (name) VALUES ('Default Organization')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Pre-seed IRS Schedule C categories
INSERT INTO expense_categories (organization_id, name, is_system) VALUES
(NULL, 'Advertising', 1),
(NULL, 'Car & Truck Expenses', 1),
(NULL, 'Commissions & Fees', 1),
(NULL, 'Contract Labor', 1),
(NULL, 'Depletion', 1),
(NULL, 'Depreciation', 1),
(NULL, 'Employee Benefits', 1),
(NULL, 'Insurance', 1),
(NULL, 'Interest - Mortgage', 1),
(NULL, 'Interest - Other', 1),
(NULL, 'Legal & Professional Services', 1),
(NULL, 'Office Expense', 1),
(NULL, 'Pension & Profit-Sharing', 1),
(NULL, 'Rent - Equipment', 1),
(NULL, 'Rent - Vehicles/Machinery', 1),
(NULL, 'Rent - Other', 1),
(NULL, 'Repairs & Maintenance', 1),
(NULL, 'Supplies', 1),
(NULL, 'Taxes & Licenses', 1),
(NULL, 'Travel & Meals', 1),
(NULL, 'Utilities', 1),
(NULL, 'Wages', 1),
(NULL, 'Other', 1);

-- VENDORS (was receipt_stores, extended with email/phone/website/tax_id/category)
CREATE TABLE IF NOT EXISTS vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    website VARCHAR(255) NULL,
    tax_id VARCHAR(50) NULL,
    default_category_id INT NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    address TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vendor_org_name (organization_id, name),
    INDEX idx_vendor_org (organization_id),
    CONSTRAINT fk_vendor_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_vendor_default_cat FOREIGN KEY (default_category_id) REFERENCES expense_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RECEIPTS
CREATE TABLE IF NOT EXISTS receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    store_id INT NULL,
    client_id INT NULL,
    project_id INT NULL,
    amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    receipt_date DATE NOT NULL,
    description TEXT NULL,
    file_path VARCHAR(255) NULL,
    file_name VARCHAR(255) NULL,
    file_size BIGINT UNSIGNED NULL,
    mime_type VARCHAR(150) NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_receipt_org (organization_id),
    INDEX idx_receipt_store (store_id),
    INDEX idx_receipt_client (client_id),
    INDEX idx_receipt_project (project_id),
    INDEX idx_receipt_date (receipt_date),
    CONSTRAINT fk_receipts_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_receipts_store FOREIGN KEY (store_id) REFERENCES vendors(id) ON DELETE SET NULL,
    CONSTRAINT fk_receipts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_receipts_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_receipts_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RECURRING EXPENSE TEMPLATES
CREATE TABLE IF NOT EXISTS recurring_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    vendor_id INT NULL DEFAULT NULL,
    category_id INT NULL DEFAULT NULL,
    client_id INT NULL DEFAULT NULL,
    project_id INT NULL DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    description VARCHAR(500) NOT NULL,
    interval_count INT NOT NULL DEFAULT 1,
    interval_unit ENUM('week','month','year') NOT NULL DEFAULT 'month',
    start_date DATE NOT NULL,
    next_expense_date DATE NULL,
    end_date DATE NULL,
    last_generated_date DATE NULL,
    generated_count INT NOT NULL DEFAULT 0,
    is_billable TINYINT(1) NOT NULL DEFAULT 0,
    is_tax_deductible TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active','paused','ended') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_by INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rec_exp_org (organization_id),
    INDEX idx_rec_exp_vendor (vendor_id),
    INDEX idx_rec_exp_category (category_id),
    INDEX idx_rec_exp_client (client_id),
    INDEX idx_rec_exp_project (project_id),
    INDEX idx_rec_exp_due (status, next_expense_date),
    CONSTRAINT fk_rec_exp_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_exp_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_exp_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_exp_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_exp_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_exp_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- EXPENSES
CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    vendor_id INT NULL DEFAULT NULL,
    category_id INT NULL DEFAULT NULL,
    client_id INT NULL DEFAULT NULL,
    project_id INT NULL DEFAULT NULL,
    receipt_id INT NULL DEFAULT NULL,
    recurring_expense_id INT NULL DEFAULT NULL,
    recurring_occurrence_date DATE NULL DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(12,2) NULL DEFAULT NULL,
    total_amount DECIMAL(12,2) NULL DEFAULT NULL,
    expense_date DATE NOT NULL,
    description TEXT NULL,
    payment_method ENUM('cash','check','card','bank_transfer','paypal','venmo','other') NULL DEFAULT NULL,
    reference_number VARCHAR(255) NULL DEFAULT NULL,
    is_billable TINYINT(1) NOT NULL DEFAULT 0,
    is_tax_deductible TINYINT(1) NOT NULL DEFAULT 1,
    is_reimbursed TINYINT(1) NOT NULL DEFAULT 0,
    is_reconciled TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending','confirmed','reimbursed','void') NOT NULL DEFAULT 'confirmed',
    notes TEXT NULL,
    created_by INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_exp_org (organization_id),
    INDEX idx_exp_vendor (vendor_id),
    INDEX idx_exp_category (category_id),
    INDEX idx_exp_client (client_id),
    INDEX idx_exp_project (project_id),
    INDEX idx_exp_recurring (recurring_expense_id),
    UNIQUE INDEX uq_exp_recurring_occurrence (recurring_expense_id, recurring_occurrence_date),
    INDEX idx_exp_date (expense_date),
    INDEX idx_exp_status (status),
    INDEX idx_exp_billable (is_billable),
    CONSTRAINT fk_exp_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_receipt FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_recurring FOREIGN KEY (recurring_expense_id) REFERENCES recurring_expenses(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FINANCIAL ASSETS
CREATE TABLE IF NOT EXISTS financial_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    vendor_id INT NULL DEFAULT NULL,
    category_id INT NULL DEFAULT NULL,
    expense_id INT NULL DEFAULT NULL,
    asset_tag VARCHAR(80) NULL DEFAULT NULL,
    name VARCHAR(190) NOT NULL,
    asset_type VARCHAR(100) NULL DEFAULT NULL,
    serial_number VARCHAR(190) NULL DEFAULT NULL,
    status ENUM('planned','active','maintenance','retired','sold','lost','disposed') NOT NULL DEFAULT 'active',
    location VARCHAR(190) NULL DEFAULT NULL,
    purchase_date DATE NULL DEFAULT NULL,
    purchase_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    depreciation_method ENUM('none','straight_line') NOT NULL DEFAULT 'straight_line',
    depreciation_start_date DATE NULL DEFAULT NULL,
    useful_life_months INT NULL DEFAULT NULL,
    salvage_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    warranty_expires_on DATE NULL DEFAULT NULL,
    disposed_on DATE NULL DEFAULT NULL,
    disposal_value DECIMAL(12,2) NULL DEFAULT NULL,
    notes TEXT NULL,
    created_by INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fin_asset_org_tag (organization_id, asset_tag),
    INDEX idx_fin_asset_org (organization_id),
    INDEX idx_fin_asset_vendor (vendor_id),
    INDEX idx_fin_asset_category (category_id),
    INDEX idx_fin_asset_expense (expense_id),
    INDEX idx_fin_asset_status (status),
    INDEX idx_fin_asset_purchase_date (purchase_date),
    CONSTRAINT fk_fin_asset_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_fin_asset_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    CONSTRAINT fk_fin_asset_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_fin_asset_expense FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE SET NULL,
    CONSTRAINT fk_fin_asset_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MODULE 005.1: Time Tracking
-- ============================================================================
CREATE TABLE IF NOT EXISTS time_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    user_id INT NOT NULL,
    client_id INT NULL,
    project_id INT NULL,
    project_code VARCHAR(64) NULL,
    contract_id INT NULL,
    invoice_id INT NULL,
    service_item_id INT NULL,
    description TEXT NULL,
    started_at DATETIME NULL,
    ended_at DATETIME NULL,
    hours DECIMAL(10,2) NOT NULL DEFAULT 0,
    billable TINYINT(1) DEFAULT 1,
    billed TINYINT(1) DEFAULT 0,
    rate DECIMAL(10,2) DEFAULT 0,
    invoice_item_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_time_entries_user (user_id),
    INDEX idx_time_entries_client (client_id),
    INDEX idx_time_entries_project (project_id),
    INDEX idx_time_entries_project_code (project_code),
    INDEX idx_time_entries_contract (contract_id),
    INDEX idx_time_entries_invoice (invoice_id),
    INDEX idx_time_entries_billable (billable),
    INDEX idx_time_entries_billed (billed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MILEAGE LOGS
CREATE TABLE IF NOT EXISTS mileage_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    user_id INT NULL DEFAULT NULL,
    client_id INT NULL DEFAULT NULL,
    project_id INT NULL DEFAULT NULL,
    trip_date DATE NOT NULL,
    start_location VARCHAR(255) NULL DEFAULT NULL,
    end_location VARCHAR(255) NULL DEFAULT NULL,
    miles DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    purpose ENUM('business','medical','moving','charitable','personal') NOT NULL DEFAULT 'business',
    description TEXT NULL,
    round_trip TINYINT(1) NOT NULL DEFAULT 0,
    bill_return_trip TINYINT(1) NOT NULL DEFAULT 0,
    mileage_rate DECIMAL(5,3) NOT NULL DEFAULT 0.670,
    is_billable TINYINT(1) NOT NULL DEFAULT 0,
    billed TINYINT(1) NOT NULL DEFAULT 0,
    invoice_id INT NULL DEFAULT NULL,
    invoice_item_id INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mileage_org (organization_id),
    INDEX idx_mileage_date (trip_date),
    INDEX idx_mileage_client (client_id),
    INDEX idx_mileage_purpose (purpose),
    INDEX idx_mileage_billable_billed (is_billable, billed),
    INDEX idx_mileage_invoice (invoice_id),
    CONSTRAINT fk_mileage_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_invoice_item FOREIGN KEY (invoice_item_id) REFERENCES invoice_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FORM CATEGORIES
CREATE TABLE IF NOT EXISTS form_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    type ENUM('file', 'folder') NOT NULL DEFAULT 'folder',
    description TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_form_cat_org (organization_id),
    INDEX idx_form_cat_type (type),
    CONSTRAINT fk_form_cat_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_form_cat_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FORM DOCUMENTS
CREATE TABLE IF NOT EXISTS form_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    category_id INT NULL,
    client_id INT NULL,
    project_id INT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_size BIGINT UNSIGNED NULL,
    mime_type VARCHAR(150) NULL,
    description TEXT NULL,
    file_path VARCHAR(255) NULL,
    status ENUM('draft', 'active', 'archived') NOT NULL DEFAULT 'draft',
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_form_doc_org (organization_id),
    INDEX idx_form_doc_category (category_id),
    INDEX idx_form_doc_client (client_id),
    INDEX idx_form_doc_project (project_id),
    CONSTRAINT fk_form_docs_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_form_docs_category FOREIGN KEY (category_id) REFERENCES form_categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_docs_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_form_docs_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_form_docs_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DISCOUNTS
CREATE TABLE IF NOT EXISTS discounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    name VARCHAR(100) NOT NULL,
    discount_type ENUM('percent', 'fixed') NOT NULL DEFAULT 'percent',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    start_date DATE NULL,
    end_date DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_discounts_org (organization_id),
    INDEX idx_discounts_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MODULE 006: Audit, Notifications & System
-- ============================================================================

-- SYSTEM AUDIT
CREATE TABLE IF NOT EXISTS system_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    organization_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id INT NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_org (organization_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AUDIT SCHEDULES
CREATE TABLE IF NOT EXISTS audit_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    report_type ENUM('audit','expense') NOT NULL DEFAULT 'audit',
    frequency ENUM('weekly', 'monthly', 'quarterly', 'annually') NOT NULL DEFAULT 'monthly',
    date_range_type VARCHAR(50) NOT NULL DEFAULT 'current_year',
    accounting_basis ENUM('cash','accrual') NOT NULL DEFAULT 'cash',
    email_addresses TEXT NOT NULL,
    include_invoices TINYINT(1) NOT NULL DEFAULT 1,
    include_unpaid_invoices TINYINT(1) NOT NULL DEFAULT 0,
    include_contracts TINYINT(1) NOT NULL DEFAULT 0,
    include_quotes TINYINT(1) NOT NULL DEFAULT 0,
    generate_csv TINYINT(1) NOT NULL DEFAULT 1,
    include_pdfs TINYINT(1) NOT NULL DEFAULT 0,
    options JSON NULL,
    filters JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    next_run_at DATETIME NULL,
    last_run_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_audit_sched_org (organization_id),
    INDEX idx_audit_sched_type (organization_id, report_type, is_active),
    INDEX idx_audit_sched_active (is_active),
    INDEX idx_audit_sched_next (next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AUDIT SCHEDULE LOGS
CREATE TABLE IF NOT EXISTS audit_schedule_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    status ENUM('pending', 'running', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    result_summary TEXT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_log_schedule (schedule_id),
    INDEX idx_audit_log_status (status),
    CONSTRAINT fk_audit_log_schedule FOREIGN KEY (schedule_id) REFERENCES audit_schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTIFICATION SETTINGS
-- NOTIFICATION LOG
-- IN-APP NOTIFICATIONS
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    organization_id INT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NULL,
    link VARCHAR(255) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user (user_id),
    INDEX idx_notifications_read (is_read),
    INDEX idx_notifications_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CRON JOB RUNS
CREATE TABLE IF NOT EXISTS cron_job_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL,
    last_run DATETIME NULL,
    status ENUM('running', 'completed', 'failed', 'success') NOT NULL DEFAULT 'running',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    result TEXT NULL,
    error_message TEXT NULL,
    UNIQUE KEY uq_cron_job_name (job_name),
    INDEX idx_cron_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- APP CONFIG
CREATE TABLE IF NOT EXISTS app_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL DEFAULT 0,
    config_key VARCHAR(100) NOT NULL,
    config_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_app_config (organization_id, config_key),
    INDEX idx_config_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MODULE 007: Public Links & Document Customization
-- ============================================================================

-- PUBLIC LINKS
CREATE TABLE IF NOT EXISTS public_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    document_type VARCHAR(50) NOT NULL,
    document_id INT NOT NULL,
    expires_at DATETIME NULL,
    expire_when_paid TINYINT(1) NOT NULL DEFAULT 0,
    revoked TINYINT(1) NOT NULL DEFAULT 0,
    redirect VARCHAR(500) NULL,
    access_count INT NOT NULL DEFAULT 0,
    last_accessed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_public_links_token (token),
    INDEX idx_public_links_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LINK RESOLVER CONFIG
CREATE TABLE IF NOT EXISTS link_resolver_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(100) NOT NULL,
    config_key VARCHAR(100) NULL,
    config_value TEXT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    credentials JSON NULL,
    default_expiration_days INT NULL,
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_link_resolver (provider, config_key),
    INDEX idx_link_resolver_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DOCUMENT CUSTOM FIELDS
CREATE TABLE IF NOT EXISTS document_custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    document_type VARCHAR(50) NOT NULL DEFAULT 'quote',
    field_name VARCHAR(100) NULL,
    field_key VARCHAR(100) NULL,
    field_label VARCHAR(100) NULL,
    field_data_type VARCHAR(50) NULL,
    field_type ENUM('text', 'number', 'date', 'boolean', 'select', 'textarea') NOT NULL DEFAULT 'text',
    field_options JSON NULL,
    default_value TEXT NULL,
    min_value DECIMAL(12,2) NULL,
    max_value DECIMAL(12,2) NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_builtin TINYINT(1) NOT NULL DEFAULT 0,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_doc_cf_org (organization_id),
    INDEX idx_doc_cf_type (document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed built-in custom fields (Deposit Required, Fulfillment Date)
INSERT IGNORE INTO document_custom_fields (document_type, field_key, field_label, field_type, is_required, is_builtin, is_enabled, display_order) VALUES
  ('regular', 'deposit', 'Deposit Required', 'text', 0, 1, 1, 1),
  ('long_term', 'deposit', 'Deposit Required', 'text', 0, 1, 1, 1),
  ('on_demand', 'deposit', 'Deposit Required', 'text', 0, 1, 1, 1),
  ('regular', 'fulfillment_date', 'Fulfillment Date (Estimated)', 'date', 0, 1, 1, 2),
  ('long_term', 'fulfillment_date', 'Fulfillment Date (Estimated)', 'date', 0, 1, 1, 2),
  ('on_demand', 'fulfillment_date', 'Fulfillment Date (Estimated)', 'date', 0, 1, 1, 2);

-- DOCUMENT SETTINGS
CREATE TABLE IF NOT EXISTS document_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL DEFAULT 0,
    document_type VARCHAR(50) NOT NULL,
    settings JSON NULL,
    setting_key VARCHAR(100) NULL,
    setting_value TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_doc_settings (organization_id, document_type, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CUSTOM FIELD VALUES
-- RATE LIMITS
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_created (identifier, created_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MODULE 007.1: Archive & Migration Tracking Tables
-- ============================================================================

CREATE TABLE IF NOT EXISTS archived_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    organization_id INT NULL,
    notes TEXT NULL,
    address_line1 VARCHAR(255) NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(2) NULL,
    postal_code VARCHAR(20) NULL,
    country VARCHAR(100) NULL DEFAULT 'US',
    created_at TIMESTAMP NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_archived_clients_client (client_id),
    INDEX idx_archived_clients_org (organization_id),
    INDEX idx_archived_clients_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS archived_entities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NULL,
    organization_id INT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NOT NULL,
    payload JSON NOT NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_arch_entities_client (client_id),
    INDEX idx_arch_entities_org (organization_id),
    INDEX idx_arch_entities_type (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE schema_migrations (
    version INT UNSIGNED NOT NULL PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    checksum CHAR(64) NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version, filename, checksum)
VALUES (0, 'baseline.sql', NULL);

-- ============================================================================
-- MODULE 008: Seed Data
-- ============================================================================

-- DEFAULT APP CONFIG
INSERT INTO app_config (config_key, config_value) VALUES
    ('brand_name', 'Project Alpha'),
    ('from_company', ''),
    ('timezone', 'UTC'),
    ('primary_state', ''),
    ('cron_enabled', '1'),
    ('link_resolver_enabled', '0'),
    ('link_resolver_scan_mode', 'quick'),
    ('link_resolver_daily_scan_enabled', '0'),
    ('link_resolver_invoice_auto_attach_enabled', '0'),
    ('project_specific_links_enabled', '0'),
    ('invoice_content_links_enabled', '0'),
    ('invoice_missing_content_links_behavior', 'warn'),
    ('invoice_auto_send_due_7days', '1'),
    ('invoice_auto_send_overdue_weekly', '1'),
    ('invoice_auto_email_on_generate', '1'),
    ('payment_receipts_enabled', '1'),
    ('notify_signed_contract_uploaded', '1'),
    ('notify_invoice_paid', '1'),
    ('notify_invoice_paid_regular', '1'),
    ('notify_invoice_paid_on_demand', '1'),
    ('notify_invoice_paid_long_term', '1'),
    ('notify_invoice_paid_project', '1'),
    ('notify_client_onboarding_submit', '1'),
    ('workforce_allow_non_admin_time_management', '0'),
    ('workforce_allow_non_admin_time_approval', '0'),
    ('default_mileage_rate', '0.670'),
    ('default_mileage_include_return_trip', '1'),
    ('default_mileage_bill_return_trip', '0'),
    ('email_no_reply_notice_enabled', '0'),
    ('email_no_reply_notice_text', 'This is an automated message. Please do not reply to this email.')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- ============================================================================
-- MODULE 009: Webhook Event Log
-- ============================================================================
-- Records every Stripe webhook delivery attempt for observability / debugging.
CREATE TABLE IF NOT EXISTS webhook_event_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(100) NOT NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    event_type VARCHAR(100) NULL,
    event_id VARCHAR(100) NULL,
    signature_present TINYINT(1) NOT NULL DEFAULT 0,
    signature_valid TINYINT(1) NULL,
    ip_address VARCHAR(45) NULL,
    payload_size INT NOT NULL DEFAULT 0,
    http_response_code INT NULL,
    error_message TEXT NULL,
    raw_payload LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_webhook_event_log_received_at (received_at),
    INDEX idx_webhook_event_log_endpoint (endpoint),
    INDEX idx_webhook_event_log_event_id (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
