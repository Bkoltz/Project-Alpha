-- database/migrations/000_database.sql
-- Consolidated database schema for project_alpha
-- This file contains all table definitions and structure in one place
-- Suitable for fresh database initialization
CREATE DATABASE IF NOT EXISTS project_alpha CHARACTER
SET
  utf8mb4 COLLATE utf8mb4_unicode_ci;

USE project_alpha;

-- ============================================================================
-- USERS & AUTHENTICATION
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    username VARCHAR(50) NULL,
    role ENUM ('admin', 'user') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT (1) NOT NULL DEFAULT 0,
    used TINYINT (1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_resets_user (user_id),
    INDEX idx_resets_token (token),
    CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    email VARCHAR(255) NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_ip (ip),
    INDEX idx_attempts_email (email),
    INDEX idx_attempts_time (attempted_at)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- API MANAGEMENT
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
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
    INDEX idx_api_keys_revoked (revoked_at)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS api_usage (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT NOT NULL,
    used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_usage_key_time (api_key_id, used_at),
    CONSTRAINT fk_api_usage_key FOREIGN KEY (api_key_id) REFERENCES api_keys (id) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- ORGANIZATIONS
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    notes TEXT NULL,
    tax_exempt_file VARCHAR(255) NULL,
    tax_exempt_uploaded_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_organizations_name (name)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- CLIENTS 
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NULL,
    phone VARCHAR(50) NULL,
    organization_id INT NULL,
    notes TEXT NULL,
    address_line1 VARCHAR(200) NULL,
    address_line2 VARCHAR(200) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(100) NOT NULL,
    postal VARCHAR(20) NULL,
    country VARCHAR(100) NOT NULL DEFAULT 'USA',
    archived TINYINT (1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_clients_name (name),
    INDEX idx_clients_archived (archived),
    INDEX idx_clients_organization (organization_id),
    CONSTRAINT fk_clients_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE SET NULL
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS archived_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NULL,
    phone VARCHAR(50) NULL,
    organization_id INT NULL,
    notes TEXT NULL,
    address_line1 VARCHAR(200) NULL,
    address_line2 VARCHAR(200) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(100) NULL,
    postal VARCHAR(20) NULL,
    country VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS archived_entities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    entity_id INT NOT NULL,
    payload JSON NOT NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_arch_entities_client (client_id),
    INDEX idx_arch_entities_type (entity_type)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- LINKS (for organization/client resources)
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS link (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM ('client', 'organization') NOT NULL,
    entity_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    url VARCHAR(500) NOT NULL,
    type ENUM (
      'manual',
      'auto_dropbox',
      'auto_gdrive',
      'auto_s3'
    ) NOT NULL,
    expiration_date DATE NULL,
    is_expired TINYINT (1) DEFAULT 0,
    ignore_auto_generation TINYINT (1) DEFAULT 0,
    last_verified TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_link_entity (entity_type, entity_id),
    INDEX idx_link_expired (is_expired),
    INDEX idx_link_expiration (expiration_date),
    INDEX idx_link_ignore (ignore_auto_generation)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- PROJECT MANAGEMENT
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS project_counters (
    prefix VARCHAR(32) PRIMARY KEY,
    next_seq INT NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS project_meta (
    project_code VARCHAR(64) PRIMARY KEY,
    client_id INT NOT NULL,
    notes TEXT NULL,
    terms TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_project_meta_client (client_id),
    CONSTRAINT fk_project_meta_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- PROJECTS (Manual Parent Grouping)
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    client_id INT NULL,
    parent_id INT NULL,
    organization_id INT NULL,
    status ENUM (
      'not_started',
      'active',
      'overdue',
      'completed',
      'cancelled'
    ) NOT NULL DEFAULT 'not_started',
    estimated_start DATE NULL,
    estimated_end DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_projects_client (client_id),
    INDEX idx_projects_parent (parent_id),
    INDEX idx_projects_organization (organization_id),
    INDEX idx_projects_status (status)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS project_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    document_type ENUM (
      'quote',
      'contract',
      'invoice',
      'recurring_invoice',
      'long_term_contract',
      'on_demand_contract'
    ) NOT NULL,
    document_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_project_documents_project (project_id),
    INDEX idx_project_documents_type (document_type, document_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- QUOTES
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS quotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    project_id INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM ('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    discount_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    fulfillment_date DATE NULL,
    is_long_term TINYINT (1) NOT NULL DEFAULT 0,
    is_on_demand TINYINT (1) NOT NULL DEFAULT 0,
    start_date DATE NULL,
    end_date DATE NULL,
    billing_interval_count INT NULL DEFAULT 1,
    billing_interval_unit ENUM ('day', 'week', 'month', 'year') NULL DEFAULT 'month',
    pricing_type ENUM ('per_invoice', 'fixed_total', 'on_demand') NULL,
    price_per_invoice DECIMAL(12, 2) NULL,
    scope TEXT NULL,
    custom_fields JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_quotes_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
    INDEX idx_quotes_client (client_id),
    INDEX idx_quotes_status (status),
    INDEX idx_quotes_doc_number (doc_number),
    INDEX idx_quotes_project_code (project_code),
    INDEX idx_quotes_project_id (project_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS quote_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NOT NULL,
    item VARCHAR(255) NOT NULL,
    description TEXT NULL,
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_quote_items_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE CASCADE,
    INDEX idx_quote_items_quote (quote_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- CONTRACTS
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NULL,
    client_id INT NOT NULL,
    project_id INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM (
      'pending',
      'active',
      'completed',
      'cancelled',
      'denied',
      'void'
    ) NOT NULL DEFAULT 'pending',
    discount_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_paid DECIMAL(12, 2) NOT NULL DEFAULT 0,
    signed_pdf_path VARCHAR(255) NULL,
    completed_at TIMESTAMP NULL,
    voided_at TIMESTAMP NULL,
    scheduled_date DATE NULL,
    terms TEXT NULL,
    estimated_completion VARCHAR(200) NULL,
    fulfillment_date DATE NULL,
    weather_pending TINYINT (1) NOT NULL DEFAULT 0,
    scope TEXT NULL,
    custom_fields JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_contracts_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE SET NULL,
    CONSTRAINT fk_contracts_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
    INDEX idx_contracts_client (client_id),
    INDEX idx_contracts_status (status),
    INDEX idx_contracts_doc_number (doc_number),
    INDEX idx_contracts_project_code (project_code),
    INDEX idx_contracts_project_id (project_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS contract_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    item VARCHAR(255) NOT NULL,
    description TEXT NULL,
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_contract_items_contract FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE CASCADE,
    INDEX idx_contract_items_contract (contract_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- INVOICES
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NULL,
    quote_id INT NULL,
    long_term_contract_id INT NULL,
    on_demand_contract_id INT NULL,
    client_id INT NOT NULL,
    project_id INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    discount_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12, 2) NULL DEFAULT NULL,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    status ENUM ('unpaid', 'partial', 'paid', 'void') NOT NULL DEFAULT 'unpaid',
    due_date DATE NULL,
    scheduled_date DATE NULL,
    estimated_completion VARCHAR(200) NULL,
    fulfillment_date DATE NULL,
    weather_pending TINYINT (1) NOT NULL DEFAULT 0,
    scope TEXT NULL,
    custom_fields JSON NULL,
    is_deposit_invoice TINYINT (1) DEFAULT 0,
    parent_contract_type ENUM (
      'contract',
      'long_term_contract',
      'on_demand_contract'
    ) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_invoice_deposit (is_deposit_invoice),
    CONSTRAINT fk_invoices_contract FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
    INDEX idx_invoices_client (client_id),
    INDEX idx_invoices_status (status),
    INDEX idx_invoices_total (total),
    INDEX idx_invoices_doc_number (doc_number),
    INDEX idx_invoices_project_code (project_code),
    INDEX idx_invoices_project_id (project_id),
    INDEX idx_invoices_ltc (long_term_contract_id),
    INDEX idx_invoices_odc (on_demand_contract_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    item VARCHAR(255) NOT NULL,
    description TEXT NULL,
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    is_extra_charge TINYINT (1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    INDEX idx_invoice_items_invoice (invoice_id),
    INDEX idx_invoice_items_extra (is_extra_charge)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- ITEM LIBRARY
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS item_library (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    is_active TINYINT (1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_item_active (is_active),
    INDEX idx_item_name (item_name)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- TAX RATES
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS tax_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    country VARCHAR(100) NOT NULL DEFAULT 'USA',
    state VARCHAR(100) NULL,
    county VARCHAR(100) NULL,
    rate DECIMAL(5, 2) NOT NULL DEFAULT 0,
    is_active TINYINT (1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tax_country (country),
    INDEX idx_tax_state (state),
    INDEX idx_tax_county (county)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- PAYMENTS
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    method VARCHAR(50) NULL,
    check_number VARCHAR(100) NULL,
    notes TEXT NULL,
    stripe_payment_intent_id VARCHAR(100) NULL,
    stripe_subscription_id VARCHAR(255) NULL,
    stripe_charge_id VARCHAR(255) NULL,
    auto_pay_attempt TINYINT (1) DEFAULT 0,
    payment_method_id INT NULL,
    status ENUM ('pending', 'succeeded', 'failed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payments_stripe_sub (stripe_subscription_id),
    INDEX idx_payments_auto_pay (auto_pay_attempt),
    CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    INDEX idx_payments_invoice (invoice_id),
    INDEX idx_payments_status (status)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- PAYMENT METHODS
-- ============================================================================
-- we need something similar to the following:
-- PaymentMethod
-- - id
-- - user_id (or org_id)
-- - type (enum: stripe, paypal, venmo)
-- - config (json: API keys, account IDs)
-- - active (boolean)
-- ============================================================================
-- PUBLIC LINKS
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS public_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(16) NOT NULL,
    record_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    redirect VARCHAR(255) NULL,
    expires_at DATETIME NOT NULL,
    revoked TINYINT (1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_public_token (token),
    INDEX idx_public_type_record (type, record_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Notifications for automated invoice emails (track per-invoice sends)
CREATE TABLE
  IF NOT EXISTS invoice_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    type VARCHAR(32) NOT NULL, -- e.g. 'due_7', 'overdue_weekly'
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invnot_invoice (invoice_id),
    INDEX idx_invnot_type (type),
    CONSTRAINT fk_invnot_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- LONG-TERM CONTRACTS
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS long_term_contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NULL,
    base_contract_id INT NULL,
    client_id INT NOT NULL,
    project_id INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM (
      'draft',
      'pending',
      'active',
      'paused',
      'cancelled',
      'completed'
    ) NOT NULL DEFAULT 'pending',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    billing_interval_count INT NOT NULL DEFAULT 1,
    billing_interval_unit ENUM ('day', 'week', 'month', 'year') NOT NULL DEFAULT 'month',
    pricing_type ENUM ('fixed_total', 'per_invoice') NOT NULL DEFAULT 'per_invoice',
    price_per_invoice DECIMAL(12, 2) NULL,
    discount_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_paid DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total_invoiced DECIMAL(12, 2) NOT NULL DEFAULT 0,
    next_invoice_date DATE NULL,
    last_invoice_date DATE NULL,
    signed_pdf_path VARCHAR(255) NULL,
    scope TEXT NULL,
    terms TEXT NULL,
    custom_fields JSON NULL,
    auto_pay_enabled TINYINT (1) DEFAULT 0,
    payment_method_id INT NULL,
    stripe_subscription_id VARCHAR(255) NULL,
    invoice_count INT NULL COMMENT 'For fixed_total pricing: number of invoices to divide total',
    invoices_generated INT DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ltc_client (client_id),
    INDEX idx_ltc_status (status),
    INDEX idx_ltc_doc (doc_number),
    INDEX idx_ltc_project (project_code),
    INDEX idx_ltc_project_id (project_id),
    INDEX idx_ltc_next_invoice (next_invoice_date),
    INDEX idx_ltc_auto_pay (auto_pay_enabled),
    INDEX idx_ltc_stripe_sub (stripe_subscription_id),
    CONSTRAINT fk_ltc_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_ltc_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE SET NULL,
    CONSTRAINT fk_ltc_base_contract FOREIGN KEY (base_contract_id) REFERENCES contracts (id) ON DELETE SET NULL
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS long_term_contract_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    long_term_contract_id INT NOT NULL,
    item VARCHAR(255) NOT NULL,
    description TEXT NULL,
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_ltc_items_contract FOREIGN KEY (long_term_contract_id) REFERENCES long_term_contracts (id) ON DELETE CASCADE,
    INDEX idx_ltc_items_contract (long_term_contract_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- ON-DEMAND CONTRACTS
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS on_demand_contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NULL,
    client_id INT NOT NULL,
    project_id INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM (
      'draft',
      'pending',
      'active',
      'paused',
      'cancelled',
      'completed'
    ) NOT NULL DEFAULT 'pending',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    billing_interval_count INT NOT NULL DEFAULT 1,
    billing_interval_unit ENUM ('day', 'week', 'month', 'year') NOT NULL DEFAULT 'month',
    discount_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    price_per_invoice DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_paid DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total_invoiced DECIMAL(12, 2) NOT NULL DEFAULT 0,
    invoice_count INT NOT NULL DEFAULT 0,
    last_invoice_date DATE NULL,
    signed_pdf_path VARCHAR(255) NULL,
    scope TEXT NULL,
    terms TEXT NULL,
    custom_fields JSON NULL,
    auto_pay_enabled TINYINT (1) DEFAULT 0,
    payment_method_id INT NULL,
    invoice_type ENUM ('set_amount', 'itemized', 'general_writeup') DEFAULT 'set_amount',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_odc_client (client_id),
    INDEX idx_odc_status (status),
    INDEX idx_odc_doc (doc_number),
    INDEX idx_odc_project (project_code),
    INDEX idx_odc_project_id (project_id),
    INDEX idx_odc_end_date (end_date),
    INDEX idx_odc_auto_pay (auto_pay_enabled),
    INDEX idx_odc_invoice_type (invoice_type),
    CONSTRAINT fk_odc_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_odc_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE SET NULL
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS on_demand_contract_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    on_demand_contract_id INT NOT NULL,
    item VARCHAR(255) NOT NULL,
    description TEXT NULL,
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_odc_items_contract FOREIGN KEY (on_demand_contract_id) REFERENCES on_demand_contracts (id) ON DELETE CASCADE,
    INDEX idx_odc_items_contract (on_demand_contract_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- ON-DEMAND INVOICES
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS on_demand_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    on_demand_contract_id INT NOT NULL,
    invoice_id INT NOT NULL,
    invoice_number INT NULL,
    amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    status ENUM ('draft', 'sent', 'paid', 'overdue', 'cancelled') NOT NULL DEFAULT 'draft',
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    due_date DATE NULL,
    notes TEXT NULL,
    CONSTRAINT fk_odinv_contract FOREIGN KEY (on_demand_contract_id) REFERENCES on_demand_contracts (id) ON DELETE CASCADE,
    CONSTRAINT fk_odinv_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    INDEX idx_odinv_contract (on_demand_contract_id),
    INDEX idx_odinv_invoice (invoice_id),
    INDEX idx_odinv_status (status),
    INDEX idx_odinv_due_date (due_date)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- RECURRING INVOICES (FUTURE USE)
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS recurring_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    project_id INT NULL,
    template_invoice_id INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM ('active', 'paused', 'cancelled') NOT NULL DEFAULT 'active',
    interval_unit ENUM ('day', 'week', 'month', 'year') NOT NULL DEFAULT 'month',
    interval_count INT NOT NULL DEFAULT 1,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    next_run_date DATE NULL,
    last_run_date DATE NULL,
    max_occurrences INT NULL,
    occurrences_generated INT NOT NULL DEFAULT 0,
    proration TINYINT (1) NOT NULL DEFAULT 0,
    anchor_day TINYINT NULL,
    discount_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_recinv_client (client_id),
    INDEX idx_recinv_status (status),
    INDEX idx_recinv_next (next_run_date),
    INDEX idx_recinv_project (project_code),
    INDEX idx_recinv_project_id (project_id),
    CONSTRAINT fk_recinv_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_recinv_template FOREIGN KEY (template_invoice_id) REFERENCES invoices (id) ON DELETE SET NULL
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- PAYMENT METHODS & AUTO-PAY
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    organization_id INT NULL,
    provider ENUM ('stripe', 'paypal', 'venmo') NOT NULL,
    provider_name VARCHAR(100) NULL,
    config JSON NOT NULL,
    is_active TINYINT (1) DEFAULT 1,
    is_default TINYINT (1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payment_user (user_id),
    INDEX idx_payment_org (organization_id),
    INDEX idx_payment_active (is_active)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- LINK RESOLVER CONFIGURATION
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS link_resolver_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider ENUM ('dropbox', 'gdrive', 's3') NOT NULL,
    is_enabled TINYINT (1) DEFAULT 0,
    credentials JSON NOT NULL,
    default_expiration_days INT DEFAULT 365,
    org_level_only TINYINT (1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_provider (provider)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- CONTRACT SIGNATURES
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS contract_signatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NULL,
    long_term_contract_id INT NULL,
    on_demand_contract_id INT NULL,
    signer_title VARCHAR(255) NOT NULL,
    signer_name VARCHAR(255) NULL,
    signer_email VARCHAR(255) NULL,
    signature_data TEXT NULL,
    signed_at TIMESTAMP NULL,
    display_order INT NOT NULL DEFAULT 1,
    is_required TINYINT (1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE CASCADE,
    FOREIGN KEY (long_term_contract_id) REFERENCES long_term_contracts (id) ON DELETE CASCADE,
    FOREIGN KEY (on_demand_contract_id) REFERENCES on_demand_contracts (id) ON DELETE CASCADE,
    INDEX idx_sig_contract (contract_id),
    INDEX idx_sig_ltc (long_term_contract_id),
    INDEX idx_sig_odc (on_demand_contract_id),
    INDEX idx_sig_signed (signed_at)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- SYSTEM AUDIT
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS system_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(16) NOT NULL,
    category VARCHAR(64) NOT NULL,
    actor_type VARCHAR(32) NULL,
    actor_id INT NULL,
    ip VARCHAR(45) NULL,
    message TEXT NULL,
    payload JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_category (category),
    INDEX idx_audit_actor (actor_type, actor_id),
    INDEX idx_audit_created (created_at)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS `audit_schedules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `frequency` ENUM ('weekly', 'monthly', 'quarterly', 'annually') NOT NULL,
    `date_range_type` ENUM (
      'last_week',
      'last_month',
      'last_quarter',
      'last_year',
      'current_year',
      'all_time'
    ) NOT NULL DEFAULT 'current_year',
    `email_addresses` TEXT NOT NULL COMMENT 'JSON array of email addresses',
    `options` JSON NULL COMMENT 'Additional options: include_contracts, include_quotes, include_pdfs, include_unpaid_invoices',
    `is_active` TINYINT (1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `next_run_at` DATETIME NULL COMMENT 'Next scheduled execution time',
    `last_run_at` DATETIME NULL COMMENT 'Last successful execution time'
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Index for finding due schedules
CREATE INDEX idx_next_run ON audit_schedules (next_run_at, is_active);

-- Add schedule execution log table
CREATE TABLE
  IF NOT EXISTS `audit_schedule_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `schedule_id` INT NOT NULL,
    `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM ('success', 'failed') NOT NULL,
    `error_message` TEXT NULL,
    `file_path` VARCHAR(500) NULL COMMENT 'Path to generated audit file',
    `email_sent` TINYINT (1) NOT NULL DEFAULT 0,
    FOREIGN KEY (`schedule_id`) REFERENCES `audit_schedules` (`id`) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE INDEX idx_schedule_logs ON audit_schedule_logs (schedule_id, executed_at);

-- ============================================================================
-- RECEIPTS
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS receipt_stores (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    store_name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE,
    UNIQUE KEY unique_store_org (org_id, store_name),
    INDEX idx_store_org (org_id),
    INDEX idx_store_name (store_name)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS receipts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    store_name VARCHAR(255) NULL,
    receipt_date DATE NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL,
    INDEX idx_receipt_org (org_id),
    INDEX idx_receipt_date (receipt_date),
    INDEX idx_receipt_store (store_name)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- FORMS & DOCUMENTS
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS form_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    type ENUM ('file', 'folder') NOT NULL DEFAULT 'folder' COMMENT 'file=single document, folder=multiple documents',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    INDEX idx_form_cat_org (org_id),
    INDEX idx_form_cat_type (type)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS form_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    project_id INT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_size INT UNSIGNED NULL,
    mime_type VARCHAR(100) NULL,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES form_categories (id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL,
    FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
    INDEX idx_form_doc_category (category_id),
    INDEX idx_form_doc_uploaded (uploaded_at),
    INDEX idx_form_doc_project (project_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- DOCUMENT CUSTOMIZATION SETTINGS
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS document_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_type ENUM ('regular', 'long_term', 'on_demand') NOT NULL,
    settings JSON NOT NULL COMMENT 'Customization settings for document type',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_doc_type (document_type)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Insert default settings for each document type
INSERT IGNORE INTO document_settings (document_type, settings)
VALUES
  (
    'regular',
    '{"show_deposit":true,"show_fulfillment_date":true,"show_scope":true}'
  ),
  (
    'long_term',
    '{"show_deposit":true,"show_fulfillment_date":false,"show_scope":true,"show_billing_settings":true}'
  ),
  (
    'on_demand',
    '{"show_deposit":true,"show_fulfillment_date":false,"show_scope":true,"show_billing_settings":false}'
  );

-- ============================================================================
-- DOCUMENT CUSTOM FIELDS
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS document_custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_type ENUM ('regular', 'long_term', 'on_demand') NOT NULL,
    field_key VARCHAR(100) NOT NULL COMMENT 'Internal key like pickup_date',
    field_label VARCHAR(255) NOT NULL COMMENT 'Display label like Pick Up Date',
    field_type ENUM ('text', 'date', 'number', 'textarea', 'select') NOT NULL DEFAULT 'text',
    field_options JSON NULL COMMENT 'For select fields, array of options',
    is_required TINYINT (1) NOT NULL DEFAULT 0,
    is_builtin TINYINT (1) NOT NULL DEFAULT 0 COMMENT 'Built-in fields cannot be deleted',
    is_enabled TINYINT (1) NOT NULL DEFAULT 1 COMMENT 'Whether field is shown',
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_field_key_type (document_type, field_key),
    INDEX idx_doc_type_enabled (document_type, is_enabled),
    INDEX idx_display_order (document_type, display_order)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Seed built-in fields for all document types
-- Deposit field (composite: type + value, handled specially in UI)
INSERT IGNORE INTO document_custom_fields (
  document_type,
  field_key,
  field_label,
  field_type,
  is_required,
  is_builtin,
  is_enabled,
  display_order
)
VALUES
  (
    'regular',
    'deposit',
    'Deposit Required',
    'text',
    0,
    1,
    1,
    1
  ),
  (
    'long_term',
    'deposit',
    'Deposit Required',
    'text',
    0,
    1,
    1,
    1
  ),
  (
    'on_demand',
    'deposit',
    'Deposit Required',
    'text',
    0,
    1,
    1,
    1
  );

-- Fulfillment date field
INSERT IGNORE INTO document_custom_fields (
  document_type,
  field_key,
  field_label,
  field_type,
  is_required,
  is_builtin,
  is_enabled,
  display_order
)
VALUES
  (
    'regular',
    'fulfillment_date',
    'Fulfillment Date (Estimated)',
    'date',
    0,
    1,
    1,
    2
  ),
  (
    'long_term',
    'fulfillment_date',
    'Fulfillment Date (Estimated)',
    'date',
    0,
    1,
    1,
    2
  ),
  (
    'on_demand',
    'fulfillment_date',
    'Fulfillment Date (Estimated)',
    'date',
    0,
    1,
    1,
    2
  );

-- ============================================================================
-- NOTIFICATION SETTINGS & LOGS
-- ============================================================================
CREATE TABLE
  IF NOT EXISTS notification_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    is_enabled TINYINT (1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_setting (setting_key)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS notification_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    notification_type VARCHAR(100) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NULL,
    entity_type VARCHAR(50) NULL,
    entity_id INT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM ('sent', 'failed', 'pending') DEFAULT 'sent',
    error_message TEXT NULL,
    INDEX idx_notif_type (notification_type),
    INDEX idx_notif_entity (entity_type, entity_id),
    INDEX idx_notif_sent (sent_at)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================================
-- DEFAULT DATA
-- ============================================================================
-- Insert default organization
INSERT INTO organizations (id, name, notes, created_at, updated_at)
VALUES
  (
    1,
    'Default Organization',
    'Default organization for system (delete if not needed)',
    NOW(),
    NOW()
  );

-- Insert default admin user (username: admin, password: admin123)
-- Password hash is bcrypt hash of 'admin123'
INSERT INTO users (
  id,
  email,
  username,
  password_hash,
  role,
  created_at,
  updated_at
)
VALUES
  (
    1,
    'admin@localhost',
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin',
    NOW(),
    NOW()
  );