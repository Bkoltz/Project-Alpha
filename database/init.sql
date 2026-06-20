-- Project Alpha - Database Initialization
-- Single source of truth - all modules concatenated
-- 
-- To rebuild: docker compose down -v && docker compose up --build
-- ============================================================================

CREATE DATABASE IF NOT EXISTS project_alpha CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE project_alpha;

-- ============================================================================
-- MODULE 001: Authentication & Identity
-- ============================================================================

-- USERS
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    username VARCHAR(50) NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    force_password_reset TINYINT(1) NOT NULL DEFAULT 0,
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
-- MODULE 002: Organizations, API Keys & Webhooks
-- ============================================================================

-- ORGANIZATIONS
CREATE TABLE IF NOT EXISTS organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    notes TEXT NULL,
    tax_exempt_file VARCHAR(255) NULL,
    tax_exempt_uploaded_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_organizations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USER-ORGANIZATION MEMBERSHIP
CREATE TABLE IF NOT EXISTS user_organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    organization_id INT NOT NULL,
    role ENUM('owner', 'admin', 'member') NOT NULL DEFAULT 'member',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_org (user_id, organization_id),
    INDEX idx_uo_org (organization_id),
    CONSTRAINT fk_uo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_uo_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- PROJECTS
CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    parent_id INT NULL,
    organization_id INT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status ENUM('not_started', 'active', 'overdue', 'completed', 'cancelled') NOT NULL DEFAULT 'not_started',
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
    INDEX idx_projects_status (status),
    INDEX idx_projects_parent (parent_id),
    CONSTRAINT fk_projects_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_projects_parent FOREIGN KEY (parent_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_projects_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
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

-- ENTITY LINKS
CREATE TABLE IF NOT EXISTS entity_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('client', 'organization', 'project') NOT NULL,
    entity_id INT NOT NULL,
    title VARCHAR(255) NULL,
    url VARCHAR(500) NOT NULL,
    link_type ENUM('manual', 'auto_dropbox', 'auto_gdrive', 'auto_s3') NOT NULL DEFAULT 'manual',
    expiration_date DATE NULL,
    is_expired TINYINT(1) NOT NULL DEFAULT 0,
    ignore_auto_generation TINYINT(1) NOT NULL DEFAULT 0,
    last_verified TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_link_entity (entity_type, entity_id),
    INDEX idx_link_type (link_type),
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
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM('draft','pending','approved','denied','rejected','expired') NOT NULL DEFAULT 'draft',
    quote_type ENUM('regular','long_term','on_demand') NOT NULL DEFAULT 'regular',
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
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM('draft','pending','active','paused','completed','cancelled','denied','void') NOT NULL DEFAULT 'pending',
    contract_type ENUM('regular','long_term','on_demand') NOT NULL DEFAULT 'regular',
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
    signature_data TEXT NULL,
    signed_at TIMESTAMP NULL,
    signed_by_user_id INT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cs_contract (contract_id),
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
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM('draft','sent','unpaid','partial','paid','overdue','cancelled','void') NOT NULL DEFAULT 'draft',
    invoice_type ENUM('regular','long_term','on_demand') NOT NULL DEFAULT 'regular',
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
    sent_at TIMESTAMP NULL,
    terms TEXT NULL,
    notes TEXT NULL,
    scope TEXT NULL,
    custom_fields JSON NULL,
    stripe_session_id VARCHAR(255) NULL,
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
    CONSTRAINT fk_inv_notif_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    contract_id INT NULL,
    organization_id INT NULL,
    amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    surcharge_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_method ENUM('cash', 'check', 'card', 'bank_transfer', 'stripe', 'other') NOT NULL DEFAULT 'cash',
    payment_date DATE NOT NULL,
    reference_number VARCHAR(255) NULL,
    notes TEXT NULL,
    stripe_session_id VARCHAR(255) NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    auto_pay_attempt TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('succeeded', 'failed', 'pending') NOT NULL DEFAULT 'succeeded',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payments_client (client_id),
    INDEX idx_payments_invoice (invoice_id),
    INDEX idx_payments_contract (contract_id),
    INDEX idx_payments_date (payment_date),
    INDEX idx_payments_stripe_session (stripe_session_id),
    INDEX idx_payments_stripe_pi (stripe_payment_intent_id),
    CONSTRAINT fk_payments_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL
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
    rate DECIMAL(5, 2) NOT NULL DEFAULT 0,
    county VARCHAR(100) NULL,
    state VARCHAR(2) NULL,
    zip_code VARCHAR(10) NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tax_org (organization_id),
    INDEX idx_tax_county (county),
    INDEX idx_tax_state (state),
    INDEX idx_tax_zip (zip_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ITEM LIBRARY
CREATE TABLE IF NOT EXISTS item_library (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    category VARCHAR(100) NULL,
    sku VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_item_lib_org (organization_id),
    INDEX idx_item_lib_name (name),
    INDEX idx_item_lib_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- EXPENSE CATEGORIES (IRS Schedule C aligned)
CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    parent_id INT NULL DEFAULT NULL,
    tax_deductible TINYINT(1) NOT NULL DEFAULT 1,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    color VARCHAR(7) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_exp_cat_org (organization_id),
    INDEX idx_exp_cat_parent (parent_id),
    CONSTRAINT fk_exp_cat_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_exp_cat_parent FOREIGN KEY (parent_id) REFERENCES expense_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DEFAULT ORGANIZATION (seed early so financial module FKs resolve)
INSERT INTO organizations (name) VALUES ('Default Organization')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Pre-seed IRS Schedule C categories
INSERT INTO expense_categories (organization_id, name, is_system) VALUES
(1, 'Advertising', 1),
(1, 'Car & Truck Expenses', 1),
(1, 'Commissions & Fees', 1),
(1, 'Contract Labor', 1),
(1, 'Depletion', 1),
(1, 'Depreciation', 1),
(1, 'Employee Benefits', 1),
(1, 'Insurance', 1),
(1, 'Interest - Mortgage', 1),
(1, 'Interest - Other', 1),
(1, 'Legal & Professional Services', 1),
(1, 'Office Expense', 1),
(1, 'Pension & Profit-Sharing', 1),
(1, 'Rent - Equipment', 1),
(1, 'Rent - Vehicles/Machinery', 1),
(1, 'Rent - Other', 1),
(1, 'Repairs & Maintenance', 1),
(1, 'Supplies', 1),
(1, 'Taxes & Licenses', 1),
(1, 'Travel & Meals', 1),
(1, 'Utilities', 1),
(1, 'Wages', 1),
(1, 'Other', 1);

-- VENDORS (was receipt_stores, extended with email/phone/website/tax_id/category)
CREATE TABLE IF NOT EXISTS vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
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
    CONSTRAINT fk_vendor_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_vendor_default_cat FOREIGN KEY (default_category_id) REFERENCES expense_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RECEIPTS
CREATE TABLE IF NOT EXISTS receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
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
    CONSTRAINT fk_receipts_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_receipts_store FOREIGN KEY (store_id) REFERENCES vendors(id) ON DELETE SET NULL,
    CONSTRAINT fk_receipts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_receipts_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_receipts_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- EXPENSES
CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    vendor_id INT NULL DEFAULT NULL,
    category_id INT NULL DEFAULT NULL,
    client_id INT NULL DEFAULT NULL,
    project_id INT NULL DEFAULT NULL,
    receipt_id INT NULL DEFAULT NULL,
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
    INDEX idx_exp_date (expense_date),
    INDEX idx_exp_status (status),
    INDEX idx_exp_billable (is_billable),
    CONSTRAINT fk_exp_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_exp_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_receipt FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MILEAGE LOGS
CREATE TABLE IF NOT EXISTS mileage_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
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
    mileage_rate DECIMAL(5,3) NOT NULL DEFAULT 0.670,
    is_billable TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mileage_org (organization_id),
    INDEX idx_mileage_date (trip_date),
    INDEX idx_mileage_client (client_id),
    INDEX idx_mileage_purpose (purpose),
    CONSTRAINT fk_mileage_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_mileage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FORM CATEGORIES
CREATE TABLE IF NOT EXISTS form_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    type ENUM('file', 'folder') NOT NULL DEFAULT 'folder',
    description TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_form_cat_org (organization_id),
    INDEX idx_form_cat_type (type),
    CONSTRAINT fk_form_cat_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_cat_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FORM DOCUMENTS
CREATE TABLE IF NOT EXISTS form_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
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
    CONSTRAINT fk_form_docs_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
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

-- FINANCIAL RECORDS
CREATE TABLE IF NOT EXISTS financial_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    client_id INT NULL,
    project_id INT NULL,
    record_type ENUM('income', 'expense', 'refund', 'adjustment') NOT NULL DEFAULT 'income',
    amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    description TEXT NULL,
    transaction_date DATE NULL,
    payment_method ENUM('cash', 'check', 'card', 'bank_transfer', 'other') NULL,
    reference_number VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_financial_org (organization_id),
    INDEX idx_financial_client (client_id),
    INDEX idx_financial_type (record_type),
    INDEX idx_financial_date (transaction_date)
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
    frequency ENUM('weekly', 'monthly', 'quarterly', 'annually') NOT NULL DEFAULT 'monthly',
    date_range_type VARCHAR(50) NOT NULL DEFAULT 'current_year',
    email_addresses TEXT NOT NULL,
    include_invoices TINYINT(1) NOT NULL DEFAULT 1,
    include_unpaid_invoices TINYINT(1) NOT NULL DEFAULT 0,
    include_contracts TINYINT(1) NOT NULL DEFAULT 0,
    include_quotes TINYINT(1) NOT NULL DEFAULT 0,
    generate_csv TINYINT(1) NOT NULL DEFAULT 1,
    include_pdfs TINYINT(1) NOT NULL DEFAULT 0,
    options JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    next_run_at DATETIME NULL,
    last_run_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_audit_sched_org (organization_id),
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
-- ARCHIVED ENTITIES
-- ARCHIVED CLIENTS
-- ============================================================================
-- MODULE 008: Seed Data
-- ============================================================================

-- DEFAULT ADMIN USER
-- Password hash will be replaced by docker/start.sh runtime
INSERT INTO users (email, password_hash, username, role, force_password_reset)
VALUES ('admin@project-alpha.local', '{{ADMIN_PASSWORD_HASH}}', 'admin', 'admin', 0)
ON DUPLICATE KEY UPDATE email = VALUES(email);

-- LINK ADMIN TO DEFAULT ORGANIZATION
INSERT INTO user_organizations (user_id, organization_id, role, is_default)
VALUES (1, 1, 'owner', 1)
ON DUPLICATE KEY UPDATE role = VALUES(role), is_default = VALUES(is_default);

-- DEFAULT APP CONFIG
INSERT INTO app_config (config_key, config_value) VALUES
    ('brand_name', 'Project Alpha'),
    ('timezone', 'UTC'),
    ('primary_state', '')
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
