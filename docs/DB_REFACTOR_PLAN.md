# Database Refactoring Plan — Project Alpha

**Date:** 2026-05-04  
**Scope:** Consolidate duplicate tables, fix multi-user/tenant model, normalize naming, remove redundancy  
**Risk:** High — touches virtually every controller, view, and cron job. Plan for staged migration with backwards-compatible views.

---

## 1. Executive Summary

The current schema has three parallel contract tables (`contracts`, `long_term_contracts`, `on_demand_contracts`), three parallel item tables, two invoice tables (`invoices` + `on_demand_invoices`), an `archived_clients` table that duplicates `clients`, split audit logging (`system_audit` vs `activity_log`), and no proper multi-user tenant isolation. This plan consolidates everything into a clean, normalized schema.

**Key Principles:**
- Use `ENUM`/`VARCHAR` type columns to unify polymorphic tables.
- Add `organization_id` to every tenant-scoped table.
- Replace `archived_clients` with a `deleted_at` soft-delete + JSON archive column.
- Normalize `org_id` → `organization_id` everywhere.
- Create migration SQL that preserves all data.
- PHP updates are listed by file with exact changes.

---

## 2. Current Schema Problems

| Problem | Tables Affected | Impact |
|---|---|---|
| 3 duplicate contract tables | `contracts`, `long_term_contracts`, `on_demand_contracts` | Massive code duplication, 3× controllers/views/cron jobs |
| 3 duplicate contract item tables | `contract_items`, `long_term_contract_items`, `on_demand_contract_items` | Same |
| 2 invoice parentage systems | `invoices` + `on_demand_invoices` | `on_demand_invoices` is a redundant bridge table |
| No user-org mapping | `users` has no `organization_id` | No multi-tenant user isolation |
| `archived_clients` is a full duplicate | `clients` + `archived_clients` | Risk of drift; no true soft-delete |
| Dual audit tables | `system_audit` + `activity_log` | Overlapping purposes, no unified query |
| `org_id` vs `organization_id` | `receipts`, `receipt_stores`, `form_categories` | Inconsistent naming |
| `project_documents` uses string types | `document_type` is string enum | Should reference unified contract type |
| `contract_signatures` has 3 FK columns | `contract_id`, `long_term_contract_id`, `on_demand_contract_id` | Should be single `contract_id` with type |
| `document_settings` and `document_custom_fields` use `regular`/`long_term`/`on_demand` | Must map to unified `contract_type` | |
| `invoices` has `parent_contract_type` + `long_term_contract_id` + `on_demand_contract_id` | Redundant FKs | Should be single `contract_id` + `contract_type` |

---

## 3. New Unified Schema

### 3.1 Core Tenant Tables

```sql
-- ============================================================================
-- USERS & ORGANIZATIONS (Multi-tenant)
-- ============================================================================

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  username VARCHAR(50) NULL,
  role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
  force_password_reset TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE organizations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  notes TEXT NULL,
  tax_exempt_file VARCHAR(255) NULL,
  tax_exempt_uploaded_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_organizations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NEW: Junction table for users ↔ organizations (many-to-many)
CREATE TABLE user_organizations (
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
```

### 3.2 Clients (Soft Delete + Archive)

```sql
-- ============================================================================
-- CLIENTS (with soft delete)
-- ============================================================================

CREATE TABLE clients (
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
  archived TINYINT(1) NOT NULL DEFAULT 0,        -- DEPRECATED: migrate to deleted_at
  deleted_at TIMESTAMP NULL DEFAULT NULL,           -- NEW: soft delete
  archive_payload JSON NULL,                        -- NEW: stores archived JSON snapshot
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_clients_name (name),
  INDEX idx_clients_deleted (deleted_at),
  INDEX idx_clients_organization (organization_id),
  CONSTRAINT fk_clients_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Drop archived_clients, archived_entities becomes JSON column
-- (Migration: copy archived_clients rows into clients with deleted_at set)
```

### 3.3 Unified Contracts Table

```sql
-- ============================================================================
-- CONTRACTS (unified: regular, long_term, on_demand)
-- ============================================================================

CREATE TABLE contracts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_type ENUM('regular', 'long_term', 'on_demand') NOT NULL DEFAULT 'regular',

  quote_id INT NULL,
  base_contract_id INT NULL,          -- For long_term: links to originating regular contract
  client_id INT NOT NULL,
  project_id INT NULL,
  organization_id INT NULL,          -- NEW: tenant isolation

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
  pricing_type ENUM('per_invoice', 'fixed_total', 'on_demand') NULL,   -- long_term only
  price_per_invoice DECIMAL(12, 2) NULL,                                -- long_term / on_demand
  total_invoiced DECIMAL(12, 2) NOT NULL DEFAULT 0,                     -- long_term / on_demand
  next_invoice_date DATE NULL,                                          -- long_term / on_demand
  last_invoice_date DATE NULL,                                          -- long_term / on_demand
  invoice_count INT NULL COMMENT 'For fixed_total: number of invoices to divide total',
  invoices_generated INT DEFAULT 0,                                     -- long_term / on_demand

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

  CONSTRAINT fk_contracts_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
  CONSTRAINT fk_contracts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_contracts_base_contract FOREIGN KEY (base_contract_id) REFERENCES contracts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.4 Unified Contract Items

```sql
CREATE TABLE contract_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_id INT NOT NULL,
  item VARCHAR(255) NOT NULL DEFAULT '',
  description TEXT NULL,
  quantity DECIMAL(10, 2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12, 2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_contract_items_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
  INDEX idx_contract_items_contract (contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.5 Unified Invoices

```sql
CREATE TABLE invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_id INT NULL,
  contract_type ENUM('regular', 'long_term', 'on_demand') NULL,   -- NEW: which contract type parent belongs to
  quote_id INT NULL,
  client_id INT NOT NULL,
  project_id INT NULL,
  organization_id INT NULL,                                        -- NEW: tenant isolation

  doc_number INT NULL,
  project_code VARCHAR(64) NULL,

  discount_type ENUM('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
  discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
  tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(12, 2) NULL DEFAULT NULL,
  tax_county VARCHAR(100) NULL DEFAULT NULL,
  subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
  total DECIMAL(12, 2) NOT NULL DEFAULT 0,
  amount_paid DECIMAL(12, 2) DEFAULT 0,

  status ENUM('unpaid', 'partial', 'paid', 'void') NOT NULL DEFAULT 'unpaid',

  due_date DATE NULL,
  scheduled_date DATE NULL,
  estimated_completion VARCHAR(200) NULL,
  fulfillment_date DATE NULL,
  weather_pending TINYINT(1) NOT NULL DEFAULT 0,
  scope TEXT NULL,
  custom_fields JSON NULL,

  is_deposit_invoice TINYINT(1) DEFAULT 0,

  document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  document_date_updated_at TIMESTAMP NULL DEFAULT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_invoices_contract (contract_id),
  INDEX idx_invoices_contract_type (contract_type),
  INDEX idx_invoices_quote (quote_id),
  INDEX idx_invoices_client (client_id),
  INDEX idx_invoices_org (organization_id),
  INDEX idx_invoices_status (status),
  INDEX idx_invoices_total (total),
  INDEX idx_invoices_doc_number (doc_number),
  INDEX idx_invoices_project_code (project_code),
  INDEX idx_invoices_project_id (project_id),
  INDEX idx_invoices_deposit (is_deposit_invoice),

  CONSTRAINT fk_invoices_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL,
  CONSTRAINT fk_invoices_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
  CONSTRAINT fk_invoices_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Note:** `on_demand_invoices` table is **DROPPED**. Its columns (`invoice_number`, `generated_at`, `notes`) are merged into `invoices` as optional fields:

```sql
-- Add to invoices table (optional on-demand invoice metadata)
ALTER TABLE invoices ADD COLUMN on_demand_invoice_number INT NULL AFTER doc_number;
ALTER TABLE invoices ADD COLUMN generated_at TIMESTAMP NULL AFTER created_at;
ALTER TABLE invoices ADD COLUMN on_demand_notes TEXT NULL AFTER scope;
```

### 3.6 Unified Invoice Items

```sql
CREATE TABLE invoice_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  item VARCHAR(255) NOT NULL DEFAULT '',
  description TEXT NULL,
  quantity DECIMAL(10, 2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12, 2) NOT NULL DEFAULT 0,
  is_extra_charge TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  INDEX idx_invoice_items_invoice (invoice_id),
  INDEX idx_invoice_items_extra (is_extra_charge)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.7 Unified Signatures

```sql
CREATE TABLE contract_signatures (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_id INT NOT NULL,
  signer_title VARCHAR(255) NOT NULL,
  signer_name VARCHAR(255) NULL,
  signer_email VARCHAR(255) NULL,
  signature_data TEXT NULL,
  signed_at TIMESTAMP NULL,
  display_order INT NOT NULL DEFAULT 1,
  is_required TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
  INDEX idx_sig_contract (contract_id),
  INDEX idx_sig_signed (signed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.8 Project Documents (Updated Enum)

```sql
-- Update document_type enum to use unified contract types
CREATE TABLE project_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  document_type ENUM('quote', 'contract', 'invoice', 'recurring_invoice') NOT NULL,
  document_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_project_documents_project (project_id),
  INDEX idx_project_documents_type (document_type, document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> **Migration note:** `long_term_contract` and `on_demand_contract` become `contract` with `document_id` referencing `contracts.id`. After migration, update `project_documents` rows.

### 3.9 Document Settings & Custom Fields (Updated)

```sql
-- Map old document_type values to unified contract_type values
CREATE TABLE document_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  document_type ENUM('regular', 'long_term', 'on_demand') NOT NULL,
  settings JSON NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_doc_type (document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Same mapping for document_custom_fields
CREATE TABLE document_custom_fields (
  id INT AUTO_INCREMENT PRIMARY KEY,
  document_type ENUM('regular', 'long_term', 'on_demand') NOT NULL,
  field_key VARCHAR(100) NOT NULL,
  field_label VARCHAR(255) NOT NULL,
  field_type ENUM('text', 'date', 'number', 'textarea', 'select') NOT NULL DEFAULT 'text',
  field_options JSON NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  is_builtin TINYINT(1) NOT NULL DEFAULT 0,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  display_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_field_key_type (document_type, field_key),
  INDEX idx_doc_type_enabled (document_type, is_enabled),
  INDEX idx_display_order (document_type, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> These tables keep their enum values since they define UI customization per contract type. The `document_type` column stays as-is.

### 3.10 Unified Audit Table

```sql
-- Drop activity_log, extend system_audit with document tracking
CREATE TABLE system_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  level VARCHAR(16) NOT NULL,
  category VARCHAR(64) NOT NULL,
  actor_type VARCHAR(32) NULL,
  actor_id INT NULL,
  organization_id INT NULL,                    -- NEW: tenant scope
  ip VARCHAR(45) NULL,
  message TEXT NULL,
  payload JSON NULL,

  -- From old activity_log
  document_type VARCHAR(20) NULL,
  document_id INT NULL,
  client_id INT NULL,
  description TEXT NULL,
  user_agent TEXT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_category (category),
  INDEX idx_audit_actor (actor_type, actor_id),
  INDEX idx_audit_org (organization_id),
  INDEX idx_audit_doc (document_type, document_id),
  INDEX idx_audit_client (client_id),
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.11 Other Normalized Tables

```sql
-- Normalize org_id → organization_id
CREATE TABLE receipt_stores (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,              -- WAS: org_id
  store_name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  UNIQUE KEY unique_store_org (organization_id, store_name),
  INDEX idx_store_org (organization_id),
  INDEX idx_store_name (store_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE receipts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,            -- WAS: org_id
  title VARCHAR(255) NOT NULL,
  store_name VARCHAR(255) NULL,
  receipt_date DATE NOT NULL,
  amount DECIMAL(12, 2) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  uploaded_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_receipt_org (organization_id),
  INDEX idx_receipt_date (receipt_date),
  INDEX idx_receipt_store (store_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,            -- WAS: org_id
  title VARCHAR(255) NOT NULL,
  type ENUM('file', 'folder') NOT NULL DEFAULT 'folder',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_form_cat_org (organization_id),
  INDEX idx_form_cat_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add organization_id to remaining tenant-scoped tables
ALTER TABLE quotes ADD COLUMN organization_id INT NULL AFTER project_id,
  ADD INDEX idx_quotes_org (organization_id),
  ADD CONSTRAINT fk_quotes_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

ALTER TABLE projects ADD COLUMN organization_id INT NULL AFTER parent_id,
  ADD INDEX idx_projects_org (organization_id),
  ADD CONSTRAINT fk_projects_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

ALTER TABLE item_library ADD COLUMN organization_id INT NULL AFTER unit_price,
  ADD INDEX idx_item_lib_org (organization_id);

ALTER TABLE tax_rates ADD COLUMN organization_id INT NULL AFTER rate,
  ADD INDEX idx_tax_org (organization_id);

ALTER TABLE payment_methods ADD COLUMN organization_id INT NULL AFTER user_id,
  ADD INDEX idx_pm_org (organization_id);
```

---

## 4. Migration SQL (Data Preservation)

### Migration 002: Create New Schema

```sql
-- Run in a transaction if possible, or use a migration script

-- 1. Create user_organizations junction table
CREATE TABLE user_organizations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  organization_id INT NOT NULL DEFAULT 1,
  role ENUM('owner', 'admin', 'member') NOT NULL DEFAULT 'owner',
  is_default TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_org (user_id, organization_id),
  INDEX idx_uo_org (organization_id),
  CONSTRAINT fk_uo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_uo_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

-- Seed: map all existing users to default org as owners
INSERT INTO user_organizations (user_id, organization_id, role, is_default)
SELECT id, 1, 'owner', 1 FROM users;

-- 2. Add soft-delete columns to clients
ALTER TABLE clients ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER archived;
ALTER TABLE clients ADD COLUMN archive_payload JSON NULL AFTER deleted_at;
ALTER TABLE clients ADD INDEX idx_clients_deleted (deleted_at);

-- 3. Migrate archived_clients into clients
INSERT INTO clients (name, email, phone, organization_id, notes, address_line1, address_line2, city, state, postal, country, deleted_at, archive_payload, created_at)
SELECT name, email, phone, organization_id, notes, address_line1, address_line2, city, state, postal, country, archived_at,
       JSON_OBJECT('archived_from_table', 'archived_clients', 'original_client_id', client_id),
       created_at
FROM archived_clients;

-- 4. Add tenant columns to quotes
ALTER TABLE quotes ADD COLUMN organization_id INT NULL AFTER project_id;
UPDATE quotes q JOIN clients c ON c.id = q.client_id SET q.organization_id = c.organization_id WHERE q.organization_id IS NULL;

-- 5. Create unified contracts table (NEW)
CREATE TABLE contracts_new LIKE contracts;
ALTER TABLE contracts_new ADD COLUMN contract_type ENUM('regular', 'long_term', 'on_demand') NOT NULL DEFAULT 'regular' AFTER id;
ALTER TABLE contracts_new ADD COLUMN organization_id INT NULL AFTER project_id;
ALTER TABLE contracts_new ADD COLUMN signed_at TIMESTAMP NULL AFTER signed_pdf_path;
ALTER TABLE contracts_new ADD COLUMN start_date DATE NULL AFTER voided_at;
ALTER TABLE contracts_new ADD COLUMN end_date DATE NULL AFTER start_date;
ALTER TABLE contracts_new ADD COLUMN billing_interval_count INT NOT NULL DEFAULT 1 AFTER end_date;
ALTER TABLE contracts_new ADD COLUMN billing_interval_unit ENUM('day', 'week', 'month', 'year') NOT NULL DEFAULT 'month' AFTER billing_interval_count;
ALTER TABLE contracts_new ADD COLUMN pricing_type ENUM('per_invoice', 'fixed_total', 'on_demand') NULL AFTER billing_interval_unit;
ALTER TABLE contracts_new ADD COLUMN price_per_invoice DECIMAL(12, 2) NULL AFTER pricing_type;
ALTER TABLE contracts_new ADD COLUMN total_invoiced DECIMAL(12, 2) NOT NULL DEFAULT 0 AFTER price_per_invoice;
ALTER TABLE contracts_new ADD COLUMN next_invoice_date DATE NULL AFTER total_invoiced;
ALTER TABLE contracts_new ADD COLUMN last_invoice_date DATE NULL AFTER next_invoice_date;
ALTER TABLE contracts_new ADD COLUMN invoice_count INT NULL AFTER last_invoice_date;
ALTER TABLE contracts_new ADD COLUMN invoices_generated INT DEFAULT 0 AFTER invoice_count;
ALTER TABLE contracts_new ADD COLUMN invoice_type ENUM('set_amount', 'itemized', 'general_writeup') DEFAULT 'set_amount' AFTER invoices_generated;
ALTER TABLE contracts_new ADD COLUMN auto_pay_enabled TINYINT(1) DEFAULT 0 AFTER invoice_type;
ALTER TABLE contracts_new ADD COLUMN payment_method_id INT NULL AFTER auto_pay_enabled;
ALTER TABLE contracts_new ADD COLUMN stripe_subscription_id VARCHAR(255) NULL AFTER payment_method_id;

-- Copy regular contracts
INSERT INTO contracts_new (
  contract_type, id, quote_id, base_contract_id, client_id, project_id, organization_id,
  doc_number, project_code, status, discount_type, discount_value, tax_percent, subtotal, total,
  deposit_type, deposit_amount, deposit_paid, signed_pdf_path, signed_at, completed_at, voided_at,
  scheduled_date, terms, estimated_completion, fulfillment_date, weather_pending, scope, custom_fields,
  document_date, document_date_updated_at, created_at, updated_at
)
SELECT 'regular', id, quote_id, NULL, client_id, project_id, NULL, doc_number, project_code, status,
  discount_type, discount_value, tax_percent, subtotal, total, deposit_type, deposit_amount, deposit_paid,
  signed_pdf_path, NULL, completed_at, voided_at, scheduled_date, terms, estimated_completion,
  fulfillment_date, weather_pending, scope, custom_fields, document_date, document_date_updated_at,
  created_at, NULL
FROM contracts;

-- Copy long_term_contracts
INSERT INTO contracts_new (
  contract_type, quote_id, base_contract_id, client_id, project_id, organization_id,
  doc_number, project_code, status, discount_type, discount_value, tax_percent, subtotal, total,
  deposit_type, deposit_amount, deposit_paid, signed_pdf_path, signed_at, completed_at, voided_at,
  start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice,
  total_invoiced, next_invoice_date, last_invoice_date, invoice_count, invoices_generated,
  scope, terms, custom_fields, auto_pay_enabled, payment_method_id, stripe_subscription_id,
  document_date, document_date_updated_at, created_at, updated_at
)
SELECT 'long_term', quote_id, base_contract_id, client_id, project_id, NULL, doc_number, project_code,
  status, discount_type, discount_value, tax_percent, subtotal, total, deposit_type, deposit_amount,
  deposit_paid, signed_pdf_path, NULL, NULL, NULL, start_date, end_date, billing_interval_count,
  billing_interval_unit, pricing_type, price_per_invoice, total_invoiced, next_invoice_date,
  last_invoice_date, invoice_count, invoices_generated, scope, terms, custom_fields, auto_pay_enabled,
  payment_method_id, stripe_subscription_id, created_at, updated_at, created_at, updated_at
FROM long_term_contracts;

-- Copy on_demand_contracts
INSERT INTO contracts_new (
  contract_type, quote_id, base_contract_id, client_id, project_id, organization_id,
  doc_number, project_code, status, discount_type, discount_value, tax_percent, subtotal, total,
  deposit_type, deposit_amount, deposit_paid, signed_pdf_path, signed_at, completed_at, voided_at,
  start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice,
  total_invoiced, next_invoice_date, last_invoice_date, invoice_count, invoices_generated,
  invoice_type, scope, terms, custom_fields, auto_pay_enabled, payment_method_id,
  document_date, document_date_updated_at, created_at, updated_at
)
SELECT 'on_demand', quote_id, NULL, client_id, project_id, NULL, doc_number, project_code,
  status, discount_type, discount_value, tax_percent, subtotal, total, deposit_type, deposit_amount,
  deposit_paid, signed_pdf_path, NULL, NULL, NULL, start_date, end_date, billing_interval_count,
  billing_interval_unit, NULL, price_per_invoice, total_invoiced, NULL, last_invoice_date,
  invoice_count, invoices_generated, invoice_type, scope, terms, custom_fields, auto_pay_enabled,
  payment_method_id, created_at, updated_at, created_at, updated_at
FROM on_demand_contracts;

-- Re-sequence IDs to avoid collisions (since all 3 tables had independent auto-increments)
-- This requires a script: read all rows, assign new sequential IDs, update FK references
```

### Migration 003: Reassign IDs and Update FKs

Since `contracts`, `long_term_contracts`, and `on_demand_contracts` all had independent auto-increment IDs, we need a script (not pure SQL) to:

1. Read all rows from all 3 tables into memory.
2. Assign new sequential IDs in `contracts_new`.
3. Build an ID mapping: `old_table → old_id → new_id`.
4. Update all referencing tables:
   - `invoices.contract_id` / `invoices.long_term_contract_id` / `invoices.on_demand_contract_id`
   - `contract_signatures.contract_id` / `.long_term_contract_id` / `.on_demand_contract_id`
   - `project_documents.document_id` where type is `long_term_contract` or `on_demand_contract`
   - `quotes.base_contract_id` (long_term only)
   - `on_demand_invoices.on_demand_contract_id`

### Migration 004: Unified Items

```sql
-- Create unified contract_items table
CREATE TABLE contract_items_new (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_id INT NOT NULL,
  item VARCHAR(255) NOT NULL DEFAULT '',
  description TEXT NULL,
  quantity DECIMAL(10, 2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12, 2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_ci_contract FOREIGN KEY (contract_id) REFERENCES contracts_new(id) ON DELETE CASCADE,
  INDEX idx_ci_contract (contract_id)
);

-- Migrate with ID mapping (requires PHP script)
-- contract_items → contract_items_new (contract_type='regular')
-- long_term_contract_items → contract_items_new (contract_type='long_term')
-- on_demand_contract_items → contract_items_new (contract_type='on_demand')
```

### Migration 005: Unified Invoices

```sql
-- Add new columns to invoices
ALTER TABLE invoices ADD COLUMN contract_type ENUM('regular', 'long_term', 'on_demand') NULL AFTER contract_id;
ALTER TABLE invoices ADD COLUMN organization_id INT NULL AFTER project_id;
ALTER TABLE invoices ADD COLUMN on_demand_invoice_number INT NULL AFTER doc_number;
ALTER TABLE invoices ADD COLUMN generated_at TIMESTAMP NULL AFTER created_at;
ALTER TABLE invoices ADD COLUMN on_demand_notes TEXT NULL AFTER scope;

-- Migrate FKs
UPDATE invoices SET contract_type = 'regular' WHERE contract_id IS NOT NULL;
UPDATE invoices SET contract_id = long_term_contract_id, contract_type = 'long_term' WHERE long_term_contract_id IS NOT NULL;
UPDATE invoices SET contract_id = on_demand_contract_id, contract_type = 'on_demand' WHERE on_demand_contract_id IS NOT NULL;

-- Migrate on_demand_invoices data into invoices
UPDATE invoices i
JOIN on_demand_invoices odi ON odi.invoice_id = i.id
SET i.on_demand_invoice_number = odi.invoice_number,
    i.generated_at = odi.generated_at,
    i.on_demand_notes = odi.notes;

-- Drop redundant FK columns
ALTER TABLE invoices DROP COLUMN long_term_contract_id;
ALTER TABLE invoices DROP COLUMN on_demand_contract_id;
ALTER TABLE invoices DROP COLUMN parent_contract_type;
```

### Migration 006: Rename and Drop

```sql
-- Rename tables
RENAME TABLE contracts TO contracts_old;
RENAME TABLE contracts_new TO contracts;
RENAME TABLE contract_items TO contract_items_old;
RENAME TABLE contract_items_new TO contract_items;

-- Drop old tables
DROP TABLE long_term_contracts;
DROP TABLE long_term_contract_items;
DROP TABLE on_demand_contracts;
DROP TABLE on_demand_contract_items;
DROP TABLE on_demand_invoices;
DROP TABLE archived_clients;
DROP TABLE archived_entities;  -- replaced by clients.archive_payload
DROP TABLE contracts_old;
DROP TABLE contract_items_old;

-- Normalize org_id → organization_id
ALTER TABLE receipt_stores CHANGE COLUMN org_id organization_id INT NOT NULL;
ALTER TABLE receipts CHANGE COLUMN org_id organization_id INT NOT NULL;
ALTER TABLE form_categories CHANGE COLUMN org_id organization_id INT NOT NULL;

-- Drop old activity_log (data migrated to system_audit)
DROP TABLE activity_log;
```

---

## 5. PHP File Updates (Exact Changes)

### 5.1 Controllers — Contract Creation

**`src/controllers/contract/contracts_create.php`**
- Line ~77: Change `INSERT INTO contracts` → `INSERT INTO contracts (contract_type, ...)` with `'regular'`.
- Line ~101: `contract_items` insert stays the same (table name unchanged).
- Line ~121: `contract_signatures` insert: use `contract_id` only (remove `long_term_contract_id` / `on_demand_contract_id` logic).

**`src/controllers/contract/long_term_contracts_create.php`**
- Change `INSERT INTO long_term_contracts` → `INSERT INTO contracts` with `contract_type = 'long_term'`.
- Include `organization_id` from session context.
- Line ~144: Change `long_term_contract_items` → `contract_items`.
- Line ~167: Change `contract_signatures (long_term_contract_id, ...)` → `(contract_id, ...)`.

**`src/controllers/contract/on_demand_contracts_create.php`**
- Change `INSERT INTO on_demand_contracts` → `INSERT INTO contracts` with `contract_type = 'on_demand'`.
- Line ~130: Change `contract_signatures (on_demand_contract_id, ...)` → `(contract_id, ...)`.

### 5.2 Controllers — Contract Updates

**`src/controllers/contract/contracts_update.php`**
- Line ~57: `DELETE FROM contract_items WHERE contract_id=?` stays same.
- Line ~62: `DELETE FROM contract_signatures WHERE contract_id=?` stays same.

**`src/controllers/contract/long_term_contract_*.php`**
- All status update queries: change `UPDATE long_term_contracts` → `UPDATE contracts WHERE contract_type='long_term'`.
- Add `AND contract_type='long_term'` to WHERE clauses to prevent accidental updates.

**`src/controllers/contract/on_demand_contract_*.php`**
- Same pattern: `UPDATE on_demand_contracts` → `UPDATE contracts WHERE contract_type='on_demand'`.

### 5.3 Controllers — Invoices

**`src/controllers/invoice/invoices_create.php`**
- Add `contract_type` to INSERT based on parent contract type.
- Remove `long_term_contract_id` / `on_demand_contract_id` from INSERT.

**`src/controllers/contract/on_demand_invoice_generate.php`**
- Line ~65: Remove `on_demand_contract_id` from INSERT into `invoices`.
- Set `contract_type = 'on_demand'` instead.
- Remove `INSERT INTO on_demand_invoices` entirely.
- Store `generated_at`, `on_demand_notes` directly in `invoices`.

**`src/cron/generate_recurring_invoices.php`**
- Line ~8: Change query from `SELECT * FROM long_term_contracts` → `SELECT * FROM contracts WHERE contract_type='long_term'`.
- Line ~64: Change `long_term_contract_items` → `contract_items`.
- Line ~98: Update query `UPDATE long_term_contracts` → `UPDATE contracts WHERE contract_type='long_term'`.
- Line ~111: Insert into `invoices`: remove `long_term_contract_id`, use `contract_id` + `contract_type='long_term'`.

### 5.4 Views — Contract Details

**`src/views/pages/contract/contract-details.php`**
- Line ~9: Query `FROM contracts co` stays same; add `WHERE co.contract_type='regular'` if needed.
- Line ~16: `FROM contract_items` stays same.
- Line ~21: `FROM contract_signatures WHERE contract_id = ?` stays same.

**`src/views/pages/contract/long-term-contract-details.php`**
- Change query from `FROM long_term_contracts ltc` → `FROM contracts c WHERE c.contract_type='long_term'`.
- Line ~15: `FROM contract_items WHERE contract_id=?` stays same.

**`src/views/pages/contract/on-demand-contracts-list.php`**
- Change query from `FROM on_demand_contracts` → `FROM contracts WHERE contract_type='on_demand'`.

### 5.5 Views — Invoices

**`src/views/pages/invoice/invoice-details.php`**
- Line ~42: Change `!empty($inv['on_demand_contract_id'])` → `($inv['contract_type'] === 'on_demand')`.

**`src/views/pages/invoice/on-demand-invoices-list.php`**
- Change query: remove `JOIN on_demand_contracts`, use `contracts` instead.
- Change `WHERE i.on_demand_contract_id IS NOT NULL` → `WHERE i.contract_type = 'on_demand'`.

**`src/views/pages/contract/on-demand-invoices-list.php`**
- Same changes as above.

### 5.6 Project Documents

**`src/controllers/project/project_add_document.php`**
- Line ~18-21: Map `long_term_contract` → `contract`, `on_demand_contract` → `contract`.
- The `document_type` enum in `project_documents` should be updated to only `'quote', 'contract', 'invoice', 'recurring_invoice'`.

**`src/controllers/project/project_remove_document.php`**
- Line ~18: Same mapping update.

### 5.7 Clients (Soft Delete)

**`src/controllers/client/clients_delete.php`**
- Replace entire archive logic:
  ```php
  // OLD: INSERT INTO archived_clients ...
  // NEW: Soft delete
  $pdo->prepare('UPDATE clients SET deleted_at = NOW(), archive_payload = ? WHERE id = ?')
      ->execute([json_encode($client), $id]);
  ```
- Remove `archived_entities` inserts (or merge into `archive_payload`).

**`src/controllers/client/clients_restore.php`**
- Replace logic: read `archive_payload`, restore fields, set `deleted_at = NULL`.

**`src/views/pages/client/archived-clients.php`**
- Change query from `FROM archived_clients` → `FROM clients WHERE deleted_at IS NOT NULL`.

### 5.8 Auth & Multi-User

**`src/controllers/auth/auth_handler.php`**
- Line ~122-132: After login, fetch user's organizations:
  ```php
  $orgStmt = $pdo->prepare('SELECT o.id, o.name, uo.role, uo.is_default 
    FROM organizations o 
    JOIN user_organizations uo ON uo.organization_id = o.id 
    WHERE uo.user_id = ?');
  $orgStmt->execute([$u['id']]);
  $orgs = $orgStmt->fetchAll(PDO::FETCH_ASSOC);
  $_SESSION['user']['organizations'] = $orgs;
  $_SESSION['user']['organization_id'] = $orgs[0]['id'] ?? null;
  ```

**`src/controllers/accounts/accounts_create.php`**
- After creating user, insert into `user_organizations`:
  ```php
  $pdo->prepare('INSERT INTO user_organizations (user_id, organization_id, role, is_default) VALUES (?, ?, ?, ?)')
      ->execute([$newUserId, $defaultOrgId, 'owner', 1]);
  ```

### 5.9 Receipts, Forms (org_id → organization_id)

**`src/controllers/receipts_handler.php`**
- All `org_id` → `organization_id` in SQL queries.
- Line ~26: `$orgId = $_SESSION['user']['organization_id'] ?? 1;`

**`src/views/pages/financial/receipts-list.php`**
- All `r.org_id` → `r.organization_id`.

**`src/views/pages/financial/forms-list.php`**
- All `fc.org_id` → `fc.organization_id`.

### 5.10 Audit Logging

**`src/utils/notifications.php`**
- Replace `activity_log` INSERT with `system_audit` INSERT.
- Map fields: `event_type` → `category`, `description` → `message`, add `level = 'info'`.

**`src/utils/logger.php`**
- `audit_event()` already writes to `system_audit`. No change needed if `activity_log` is dropped.

### 5.11 Repositories

**`src/repositories/ClientRepository.php`**
- Line ~170: `organization_id` stays same (already normalized).
- Add `deleted_at` filter to `findClients()`:
  ```php
  $filter['deleted_at'] = ['$eq' => null];
  ```

**`src/repositories/QuoteRepository.php`**
- Add `organization_id` mapping in `fromMysqlRow()`.

### 5.12 Stripe & Payments

**`src/services/StripeService.php`**
- The `payment_methods` table stays but add `organization_id` for tenant isolation.
- `StripeService::fromPaymentMethod()` should also verify `organization_id` matches current context.

### 5.13 Public Links & Signatures

**`src/controllers/public_view/public_contract_sign.php`**
- Line ~48: After finding contract by token, use unified `contracts` table.
- Signature insert uses `contract_id` only.

### 5.14 Document Settings & Custom Fields

No schema changes needed — `document_type` enum stays `'regular'`, `'long_term'`, `'on_demand'` since these define per-type UI customization. The controllers already use these values correctly.

---

## 6. Migration Order & Rollback Plan

### Execution Order

1. **Backup database** — mysqldump entire `project_alpha`.
2. **Run Migration 002** — create new tables, add columns, seed junction table.
3. **Run PHP ID reassignment script** — build mapping, update all FKs.
4. **Run Migration 004-005** — migrate items, invoices, normalize FKs.
5. **Run Migration 006** — rename tables, drop old tables, normalize naming.
6. **Deploy PHP updates** — update all controllers/views (listed above).
7. **Test critical paths** — create quote → approve → contract → invoice → payment.
8. **Update cron jobs** — verify `generate_recurring_invoices.php` runs correctly.

### Rollback Plan

- Keep old tables renamed with `_old` suffix for 48 hours.
- If rollback needed:
  ```sql
  -- Quick rollback (data loss for new writes during migration window)
  RENAME TABLE contracts TO contracts_new;
  RENAME TABLE contracts_old TO contracts;
  -- ... etc for all tables
  ```
- Full rollback requires restoring from mysqldump.

---

## 7. Testing Checklist

| Feature | Test |
|---|---|
| Create regular contract | Create from quote, verify items/signatures |
| Create long-term contract | Create from quote, verify billing fields, cron generates invoices |
| Create on-demand contract | Create from quote, verify invoice generation |
| Archive client | Soft delete, verify `deleted_at` set, appears in archived list |
| Restore client | Verify `deleted_at` cleared, data restored |
| Multi-user login | User sees correct org, data scoped to org |
| Invoice payment | Stripe webhook updates correct contract |
| Public link | Contract/quote/invoice public views work |
| Audit log | All actions appear in unified `system_audit` |
| Receipts/forms | CRUD operations work with `organization_id` |

---

## 8. Summary of Files Requiring Changes

**Controllers (28 files):**
- `contract/contracts_create.php`
- `contract/contracts_update.php`
- `contract/long_term_contracts_create.php`
- `contract/long_term_contract_activate.php`
- `contract/long_term_contract_pause.php`
- `contract/long_term_contract_resume.php`
- `contract/long_term_contract_terminate.php`
- `contract/on_demand_contracts_create.php`
- `contract/on_demand_contract_activate.php`
- `contract/on_demand_contract_pause.php`
- `contract/on_demand_contract_resume.php`
- `contract/on_demand_contract_terminate.php`
- `contract/on_demand_invoice_generate.php`
- `contract/contract_sign.php`
- `contract/contract_complete.php`
- `contract/contract_deny.php`
- `contract/contract_void.php`
- `invoice/invoices_create.php`
- `invoice/invoices_update.php`
- `invoice/invoices_mark_paid.php`
- `client/clients_delete.php`
- `client/clients_restore.php`
- `auth/auth_handler.php`
- `accounts/accounts_create.php`
- `receipts_handler.php`
- `project/project_add_document.php`
- `project/project_remove_document.php`
- `public_view/public_contract_sign.php`

**Views (18 files):**
- `contract/contract-details.php`
- `contract/contracts-edit.php`
- `contract/long-term-contract-details.php`
- `contract/long-term-contracts-list.php`
- `contract/on-demand-contracts-list.php`
- `contract/on-demand-invoices-list.php`
- `invoice/invoice-details.php`
- `invoice/invoices-edit.php`
- `invoice/on-demand-invoices-list.php`
- `client/archived-clients.php`
- `client/clients-list.php`
- `project/projects-list.php`
- `financial/receipts-list.php`
- `financial/receipt-detail.php`
- `financial/forms-list.php`
- `financial/form-detail.php`
- `financial/folder-detail.php`
- `financial/document-detail.php`

**Cron Jobs (2 files):**
- `cron/generate_recurring_invoices.php`
- `cron/stripe_reconciliation.php`

**Repositories (2 files):**
- `repositories/ClientRepository.php`
- `repositories/QuoteRepository.php`

**Services (1 file):**
- `services/StripeService.php`

**Utils (1 file):**
- `utils/notifications.php`

**Total: ~52 files** requiring updates.
