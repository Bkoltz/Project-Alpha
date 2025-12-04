-- database/migrations/000_database.sql
-- Consolidated database schema for project_alpha
-- This file contains all table definitions and structure in one place
-- Suitable for fresh database initialization

CREATE DATABASE IF NOT EXISTS project_alpha CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE project_alpha;

-- ============================================================================
-- USERS & AUTHENTICATION
-- ============================================================================

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  username VARCHAR(50) NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  email VARCHAR(255) NULL,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attempts_ip (ip),
  INDEX idx_attempts_email (email),
  INDEX idx_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- API MANAGEMENT
-- ============================================================================

CREATE TABLE IF NOT EXISTS api_keys (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_usage (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  api_key_id INT NOT NULL,
  used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_api_usage_key_time (api_key_id, used_at),
  CONSTRAINT fk_api_usage_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ORGANIZATIONS
-- ============================================================================

CREATE TABLE IF NOT EXISTS organizations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  notes TEXT NULL,
  tax_exempt_file VARCHAR(255) NULL,
  tax_exempt_uploaded_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_organizations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- LINKS (for organization/client resources)
-- ============================================================================

CREATE TABLE IF NOT EXISTS link (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    client_id INT NULL,
    title VARCHAR(255) NOT NULL,
    url VARCHAR(500) NOT NULL,
    type ENUM('manual','auto_dropbox','auto_gdrive','auto_s3') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organization(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES client(id) ON DELETE CASCADE
);

-- ============================================================================
-- CLIENTS 
-- ============================================================================

CREATE TABLE IF NOT EXISTS clients (
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
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_clients_name (name),
  INDEX idx_clients_archived (archived),
  INDEX idx_clients_organization (organization_id),
  CONSTRAINT fk_clients_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS archived_clients (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS archived_entities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  entity_type VARCHAR(32) NOT NULL,
  entity_id INT NOT NULL,
  payload JSON NOT NULL,
  archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_arch_entities_client (client_id),
  INDEX idx_arch_entities_type (entity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PROJECT MANAGEMENT
-- ============================================================================

CREATE TABLE IF NOT EXISTS project_counters (
  prefix VARCHAR(32) PRIMARY KEY,
  next_seq INT NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_meta (
  project_code VARCHAR(64) PRIMARY KEY,
  client_id INT NOT NULL,
  notes TEXT NULL,
  terms TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_project_meta_client (client_id),
  CONSTRAINT fk_project_meta_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PROJECTS (Manual Parent Grouping)
-- ============================================================================
CREATE TABLE IF NOT EXISTS projects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  client_id INT NULL,
  parent_id INT NULL,
  organization_id INT NULL,
  estimated_start DATE NULL,
  estimated_end DATE NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_projects_client (client_id),
  INDEX idx_projects_parent (parent_id),
  INDEX idx_projects_organization (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  document_type ENUM('quote','contract','invoice','recurring_invoice','long_term_contract','on_demand_contract') NOT NULL,
  document_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_project_documents_project (project_id),
  INDEX idx_project_documents_type (document_type, document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- QUOTES
-- ============================================================================

CREATE TABLE IF NOT EXISTS quotes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  project_id INT NULL,
  doc_number INT NULL,
  project_code VARCHAR(64) NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
  tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  deposit_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  fulfillment_date DATE NULL,
  is_long_term TINYINT(1) NOT NULL DEFAULT 0,
  is_on_demand TINYINT(1) NOT NULL DEFAULT 0,
  start_date DATE NULL,
  end_date DATE NULL,
  billing_interval_count INT NULL DEFAULT 1,
  billing_interval_unit ENUM('day','week','month','year') NULL DEFAULT 'month',
  pricing_type ENUM('per_invoice','fixed_total','on_demand') NULL,
  price_per_invoice DECIMAL(12,2) NULL,
  scope TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_quotes_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  INDEX idx_quotes_client (client_id),
  INDEX idx_quotes_status (status),
  INDEX idx_quotes_doc_number (doc_number),
  INDEX idx_quotes_project_code (project_code)
  , INDEX idx_quotes_project_id (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quote_id INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_quote_items_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
  INDEX idx_quote_items_quote (quote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CONTRACTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS contracts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quote_id INT NULL,
  client_id INT NOT NULL,
  project_id INT NULL,
  doc_number INT NULL,
  project_code VARCHAR(64) NULL,
  status ENUM('draft','pending','active','completed','cancelled','denied','void') NOT NULL DEFAULT 'pending',
  discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
  tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  deposit_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  deposit_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  signed_pdf_path VARCHAR(255) NULL,
  completed_at TIMESTAMP NULL,
  voided_at TIMESTAMP NULL,
  scheduled_date DATE NULL,
  terms TEXT NULL,
  estimated_completion VARCHAR(200) NULL,
  fulfillment_date DATE NULL,
  weather_pending TINYINT(1) NOT NULL DEFAULT 0,
  scope TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_contracts_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
  CONSTRAINT fk_contracts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  INDEX idx_contracts_client (client_id),
  INDEX idx_contracts_status (status),
  INDEX idx_contracts_doc_number (doc_number),
  INDEX idx_contracts_project_code (project_code)
  , INDEX idx_contracts_project_id (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_id INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_contract_items_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
  INDEX idx_contract_items_contract (contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INVOICES
-- ============================================================================

CREATE TABLE IF NOT EXISTS invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_id INT NULL,
  quote_id INT NULL,
  long_term_contract_id INT NULL,
  on_demand_contract_id INT NULL,
  client_id INT NOT NULL,
  project_id INT NULL,
  doc_number INT NULL,
  project_code VARCHAR(64) NULL,
  discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
  tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('unpaid','partial','paid','void') NOT NULL DEFAULT 'unpaid',
  due_date DATE NULL,
  scheduled_date DATE NULL,
  estimated_completion VARCHAR(200) NULL,
  fulfillment_date DATE NULL,
  weather_pending TINYINT(1) NOT NULL DEFAULT 0,
  scope TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_invoices_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL,
  CONSTRAINT fk_invoices_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
  CONSTRAINT fk_invoices_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  INDEX idx_invoices_client (client_id),
  INDEX idx_invoices_status (status),
  INDEX idx_invoices_total (total),
  INDEX idx_invoices_doc_number (doc_number),
  INDEX idx_invoices_project_code (project_code),
  INDEX idx_invoices_project_id (project_id),
  INDEX idx_invoices_ltc (long_term_contract_id),
  INDEX idx_invoices_odc (on_demand_contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_extra_charge TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  INDEX idx_invoice_items_invoice (invoice_id),
  INDEX idx_invoice_items_extra (is_extra_charge)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PAYMENTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method VARCHAR(50) NULL,
  check_number VARCHAR(100) NULL,
  notes TEXT NULL,
  stripe_payment_intent_id VARCHAR(100) NULL,
  status ENUM('pending','succeeded','failed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  INDEX idx_payments_invoice (invoice_id),
  INDEX idx_payments_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PAYMENT METHODS
-- ============================================================================
-- we need something similar to the following:
PaymentMethod
- id
- user_id (or org_id)
- type (enum: stripe, paypal, venmo)
- config (json: API keys, account IDs)
- active (boolean)

-- ============================================================================
-- PUBLIC LINKS
-- ============================================================================

CREATE TABLE IF NOT EXISTS public_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(16) NOT NULL,
  record_id INT NOT NULL,
  token VARCHAR(64) NOT NULL,
  redirect VARCHAR(255) NULL,
  expires_at DATETIME NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_public_token (token),
  INDEX idx_public_type_record (type, record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications for automated invoice emails (track per-invoice sends)
CREATE TABLE IF NOT EXISTS invoice_notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  type VARCHAR(32) NOT NULL, -- e.g. 'due_7', 'overdue_weekly'
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_invnot_invoice (invoice_id),
  INDEX idx_invnot_type (type),
  CONSTRAINT fk_invnot_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- LONG-TERM CONTRACTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS long_term_contracts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quote_id INT NULL,
  base_contract_id INT NULL,
  client_id INT NOT NULL,
  project_id INT NULL,
  doc_number INT NULL,
  project_code VARCHAR(64) NULL,
  status ENUM('draft','pending','active','paused','cancelled','completed') NOT NULL DEFAULT 'pending',
  start_date DATE NOT NULL,
  end_date DATE NULL,
  billing_interval_count INT NOT NULL DEFAULT 1,
  billing_interval_unit ENUM('day','week','month','year') NOT NULL DEFAULT 'month',
  pricing_type ENUM('fixed_total','per_invoice') NOT NULL DEFAULT 'per_invoice',
  price_per_invoice DECIMAL(12,2) NULL,
  discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
  tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  deposit_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  deposit_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_invoiced DECIMAL(12,2) NOT NULL DEFAULT 0,
  next_invoice_date DATE NULL,
  last_invoice_date DATE NULL,
  signed_pdf_path VARCHAR(255) NULL,
  scope TEXT NULL,
  terms TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ltc_client (client_id),
  INDEX idx_ltc_status (status),
  INDEX idx_ltc_doc (doc_number),
  INDEX idx_ltc_project (project_code),
  INDEX idx_ltc_project_id (project_id),
  INDEX idx_ltc_next_invoice (next_invoice_date),
  CONSTRAINT fk_ltc_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_ltc_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
  CONSTRAINT fk_ltc_base_contract FOREIGN KEY (base_contract_id) REFERENCES contracts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS long_term_contract_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  long_term_contract_id INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_ltc_items_contract FOREIGN KEY (long_term_contract_id) REFERENCES long_term_contracts(id) ON DELETE CASCADE,
  INDEX idx_ltc_items_contract (long_term_contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ON-DEMAND CONTRACTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS on_demand_contracts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quote_id INT NULL,
  client_id INT NOT NULL,
  project_id INT NULL,
  doc_number INT NULL,
  project_code VARCHAR(64) NULL,
  status ENUM('draft','pending','active','paused','cancelled','completed') NOT NULL DEFAULT 'pending',
  start_date DATE NOT NULL,
  end_date DATE NULL,
  billing_interval_count INT NOT NULL DEFAULT 1,
  billing_interval_unit ENUM('day','week','month','year') NOT NULL DEFAULT 'month',
  discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
  tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  price_per_invoice DECIMAL(12,2) NOT NULL DEFAULT 0,
  deposit_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  deposit_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_invoiced DECIMAL(12,2) NOT NULL DEFAULT 0,
  invoice_count INT NOT NULL DEFAULT 0,
  last_invoice_date DATE NULL,
  signed_pdf_path VARCHAR(255) NULL,
  scope TEXT NULL,
  terms TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_odc_client (client_id),
  INDEX idx_odc_status (status),
  INDEX idx_odc_doc (doc_number),
  INDEX idx_odc_project (project_code),
  INDEX idx_odc_project_id (project_id),
  INDEX idx_odc_end_date (end_date),
  CONSTRAINT fk_odc_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_odc_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS long_term_contract_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  long_term_contract_id INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_ltc_items_contract FOREIGN KEY (long_term_contract_id) REFERENCES long_term_contracts(id) ON DELETE CASCADE,
  INDEX idx_ltc_items_contract (long_term_contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- RECURRING INVOICES (FUTURE USE)
-- ============================================================================

CREATE TABLE IF NOT EXISTS recurring_invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  project_id INT NULL,
  template_invoice_id INT NULL,
  project_code VARCHAR(64) NULL,
  status ENUM('active','paused','cancelled') NOT NULL DEFAULT 'active',
  interval_unit ENUM('day','week','month','year') NOT NULL DEFAULT 'month',
  interval_count INT NOT NULL DEFAULT 1,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  next_run_date DATE NULL,
  last_run_date DATE NULL,
  max_occurrences INT NULL,
  occurrences_generated INT NOT NULL DEFAULT 0,
  proration TINYINT(1) NOT NULL DEFAULT 0,
  anchor_day TINYINT NULL,
  discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
  tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_recinv_client (client_id),
  INDEX idx_recinv_status (status),
  INDEX idx_recinv_next (next_run_date),
  INDEX idx_recinv_project (project_code),
  INDEX idx_recinv_project_id (project_id),
  CONSTRAINT fk_recinv_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_recinv_template FOREIGN KEY (template_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
