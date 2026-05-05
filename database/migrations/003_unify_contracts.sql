-- Migration 003: Unify Contracts (3 tables → 1)
-- Consolidates contracts, long_term_contracts, on_demand_contracts into single contracts table
-- Date: 2026-05-04

USE project_alpha;

-- ============================================================================
-- STEP 1: Create unified contracts table (NEW)
-- ============================================================================
CREATE TABLE contracts_new (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_type ENUM('regular', 'long_term', 'on_demand') NOT NULL DEFAULT 'regular',

  quote_id INT NULL,
  base_contract_id INT NULL,          -- For long_term: links to originating regular contract
  client_id INT NOT NULL,
  project_id INT NULL,
  organization_id INT NULL,          -- Tenant isolation from migration 002

  doc_number INT NULL,
  project_code VARCHAR(64) NULL,

  status ENUM('draft', 'pending', 'active', 'paused', 'completed', 'cancelled', 'denied', 'void') NOT NULL DEFAULT 'pending',

  -- Billing / Pricing
  discount_type ENUM('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
  discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
  tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(12, 2) NULL DEFAULT NULL,
  tax_county VARCHAR(100) NULL DEFAULT NULL,
  subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
  total DECIMAL(12, 2) NOT NULL DEFAULT 0,

  -- Deposit
  deposit_type ENUM('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
  deposit_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
  deposit_paid DECIMAL(12, 2) NOT NULL DEFAULT 0,

  -- Long-term / On-demand specific fields (nullable for regular)
  start_date DATE NULL,
  end_date DATE NULL,
  billing_interval_count INT NOT NULL DEFAULT 1,
  billing_interval_unit ENUM('day', 'week', 'month', 'year') NOT NULL DEFAULT 'month',
  pricing_type ENUM('per_invoice', 'fixed_total', 'on_demand') NULL,
  price_per_invoice DECIMAL(12, 2) NULL,
  total_invoiced DECIMAL(12, 2) NOT NULL DEFAULT 0,
  next_invoice_date DATE NULL,
  last_invoice_date DATE NULL,
  invoice_count INT NULL COMMENT 'For fixed_total: number of invoices to divide total',
  invoices_generated INT DEFAULT 0,

  -- On-demand specific
  invoice_type ENUM('set_amount', 'itemized', 'general_writeup') DEFAULT 'set_amount',

  -- Signature / PDF
  signed_pdf_path VARCHAR(255) NULL,
  signed_at TIMESTAMP NULL,
  completed_at TIMESTAMP NULL,
  voided_at TIMESTAMP NULL,
  scheduled_date DATE NULL,

  -- Content
  scope TEXT NULL,
  terms TEXT NULL,
  fulfillment_date DATE NULL,
  weather_pending TINYINT(1) NOT NULL DEFAULT 0,
  estimated_completion VARCHAR(200) NULL,
  custom_fields JSON NULL,

  -- Auto-pay
  auto_pay_enabled TINYINT(1) DEFAULT 0,
  payment_method_id INT NULL,
  stripe_subscription_id VARCHAR(255) NULL,

  -- Document date tracking
  document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  document_date_updated_at TIMESTAMP NULL DEFAULT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_contracts_type (contract_type),
  INDEX idx_contracts_client (client_id),
  INDEX idx_contracts_org (organization_id),
  INDEX idx_contracts_status (status),
  INDEX idx_contracts_doc_number (doc_number),
  INDEX idx_contracts_project_code (project_code),
  INDEX idx_contracts_project_id (project_id),
  INDEX idx_contracts_next_invoice (next_invoice_date),
  INDEX idx_contracts_auto_pay (auto_pay_enabled),
  INDEX idx_contracts_stripe_sub (stripe_subscription_id),

  CONSTRAINT fk_contracts_new_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
  CONSTRAINT fk_contracts_new_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_contracts_new_base FOREIGN KEY (base_contract_id) REFERENCES contracts_new(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- STEP 2: Migrate data from old tables into unified table
-- ============================================================================

-- Migrate regular contracts (start with highest ID offset to avoid conflicts)
INSERT INTO contracts_new (
  id, contract_type, quote_id, base_contract_id, client_id, project_id, organization_id,
  doc_number, project_code, status,
  discount_type, discount_value, tax_percent, tax_amount, tax_county, subtotal, total,
  deposit_type, deposit_amount, deposit_paid,
  start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type,
  price_per_invoice, total_invoiced, next_invoice_date, last_invoice_date, invoice_count, invoices_generated,
  invoice_type,
  signed_pdf_path, signed_at, completed_at, voided_at, scheduled_date,
  scope, terms, fulfillment_date, weather_pending, estimated_completion, custom_fields,
  auto_pay_enabled, payment_method_id, stripe_subscription_id,
  document_date, document_date_updated_at, created_at, updated_at
)
SELECT
  id, 'regular', quote_id, NULL, client_id, project_id, NULL,
  doc_number, project_code, status,
  discount_type, discount_value, tax_percent, NULL, NULL, subtotal, total,
  deposit_type, deposit_amount, deposit_paid,
  NULL, NULL, 1, 'month', NULL,
  NULL, 0, NULL, NULL, NULL, 0,
  'set_amount',
  signed_pdf_path, NULL, completed_at, voided_at, scheduled_date,
  scope, terms, fulfillment_date, weather_pending, estimated_completion, custom_fields,
  0, NULL, NULL,
  document_date, document_date_updated_at, created_at, NULL
FROM contracts;

-- Get max ID from regular contracts for offset
SET @regular_max = (SELECT COALESCE(MAX(id), 0) FROM contracts);

-- Migrate long-term contracts with ID offset
INSERT INTO contracts_new (
  id, contract_type, quote_id, base_contract_id, client_id, project_id, organization_id,
  doc_number, project_code, status,
  discount_type, discount_value, tax_percent, tax_amount, tax_county, subtotal, total,
  deposit_type, deposit_amount, deposit_paid,
  start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type,
  price_per_invoice, total_invoiced, next_invoice_date, last_invoice_date, invoice_count, invoices_generated,
  invoice_type,
  signed_pdf_path, signed_at, completed_at, voided_at, scheduled_date,
  scope, terms, fulfillment_date, weather_pending, estimated_completion, custom_fields,
  auto_pay_enabled, payment_method_id, stripe_subscription_id,
  document_date, document_date_updated_at, created_at, updated_at
)
SELECT
  id + @regular_max, 'long_term', quote_id, base_contract_id, client_id, project_id, NULL,
  doc_number, project_code, status,
  discount_type, discount_value, tax_percent, NULL, NULL, subtotal, total,
  deposit_type, deposit_amount, deposit_paid,
  start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type,
  price_per_invoice, total_invoiced, next_invoice_date, last_invoice_date, invoice_count, invoices_generated,
  'set_amount',
  signed_pdf_path, NULL, NULL, NULL, NULL,
  scope, terms, NULL, 0, NULL, custom_fields,
  auto_pay_enabled, payment_method_id, stripe_subscription_id,
  created_at, NULL, created_at, updated_at
FROM long_term_contracts;

-- Get max ID from long-term contracts for second offset
SET @ltc_max = (SELECT COALESCE(MAX(id), 0) FROM long_term_contracts);

-- Migrate on-demand contracts with ID offset
INSERT INTO contracts_new (
  id, contract_type, quote_id, base_contract_id, client_id, project_id, organization_id,
  doc_number, project_code, status,
  discount_type, discount_value, tax_percent, tax_amount, tax_county, subtotal, total,
  deposit_type, deposit_amount, deposit_paid,
  start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type,
  price_per_invoice, total_invoiced, next_invoice_date, last_invoice_date, invoice_count, invoices_generated,
  invoice_type,
  signed_pdf_path, signed_at, completed_at, voided_at, scheduled_date,
  scope, terms, fulfillment_date, weather_pending, estimated_completion, custom_fields,
  auto_pay_enabled, payment_method_id, stripe_subscription_id,
  document_date, document_date_updated_at, created_at, updated_at
)
SELECT
  id + @regular_max + @ltc_max, 'on_demand', quote_id, NULL, client_id, project_id, NULL,
  doc_number, project_code, status,
  discount_type, discount_value, tax_percent, NULL, NULL, subtotal, price_per_invoice,
  deposit_type, deposit_amount, deposit_paid,
  start_date, end_date, billing_interval_count, billing_interval_unit, NULL,
  price_per_invoice, total_invoiced, last_invoice_date, NULL, invoice_count, invoices_generated,
  invoice_type,
  signed_pdf_path, NULL, NULL, NULL, NULL,
  scope, terms, NULL, 0, NULL, custom_fields,
  auto_pay_enabled, payment_method_id, NULL,
  created_at, NULL, created_at, updated_at
FROM on_demand_contracts;

-- ============================================================================
-- STEP 3: Create unified contract_items table
-- ============================================================================
CREATE TABLE contract_items_new (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_id INT NOT NULL,
  item VARCHAR(255) NOT NULL DEFAULT '',
  description TEXT NULL,
  quantity DECIMAL(10, 2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12, 2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_ci_new_contract FOREIGN KEY (contract_id) REFERENCES contracts_new(id) ON DELETE CASCADE,
  INDEX idx_ci_new_contract (contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate regular contract items
INSERT INTO contract_items_new (contract_id, item, description, quantity, unit_price, line_total)
SELECT contract_id, item, description, quantity, unit_price, line_total FROM contract_items;

-- Migrate long-term contract items (with ID offset)
INSERT INTO contract_items_new (contract_id, item, description, quantity, unit_price, line_total)
SELECT long_term_contract_id + @regular_max, item, description, quantity, unit_price, line_total FROM long_term_contract_items;

-- Migrate on-demand contract items (with ID offset)
INSERT INTO contract_items_new (contract_id, item, description, quantity, unit_price, line_total)
SELECT on_demand_contract_id + @regular_max + @ltc_max, item, description, quantity, unit_price, line_total FROM on_demand_contract_items;

-- ============================================================================
-- STEP 4: Store ID mapping for other tables to reference
-- ============================================================================
CREATE TABLE _contract_id_mapping (
  old_table VARCHAR(32) NOT NULL,
  old_id INT NOT NULL,
  new_id INT NOT NULL,
  PRIMARY KEY (old_table, old_id)
);

-- Build mapping
INSERT INTO _contract_id_mapping (old_table, old_id, new_id)
SELECT 'contracts', id, id FROM contracts;

INSERT INTO _contract_id_mapping (old_table, old_id, new_id)
SELECT 'long_term_contracts', id, id + @regular_max FROM long_term_contracts;

INSERT INTO _contract_id_mapping (old_table, old_id, new_id)
SELECT 'on_demand_contracts', id, id + @regular_max + @ltc_max FROM on_demand_contracts;
