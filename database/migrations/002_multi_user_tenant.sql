-- Migration 002: Multi-User Tenant Model
-- Adds user_organizations junction table and organization_id to tenant-scoped tables
-- Date: 2026-05-04

USE project_alpha;

-- ============================================================================
-- 1. Create user_organizations junction table
-- ============================================================================
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

-- Seed existing users into user_organizations (default org = 1)
INSERT INTO user_organizations (user_id, organization_id, role, is_default)
SELECT id, 1, 'owner', 1 FROM users;

-- ============================================================================
-- 2. Add organization_id to tenant-scoped tables
-- ============================================================================

-- Quotes
ALTER TABLE quotes 
  ADD COLUMN organization_id INT NULL AFTER project_id,
  ADD INDEX idx_quotes_org (organization_id),
  ADD CONSTRAINT fk_quotes_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

-- Projects
ALTER TABLE projects 
  ADD COLUMN organization_id INT NULL AFTER parent_id,
  ADD INDEX idx_projects_org (organization_id),
  ADD CONSTRAINT fk_projects_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

-- Item Library
ALTER TABLE item_library 
  ADD COLUMN organization_id INT NULL AFTER unit_price,
  ADD INDEX idx_item_lib_org (organization_id);

-- Tax Rates
ALTER TABLE tax_rates 
  ADD COLUMN organization_id INT NULL AFTER rate,
  ADD INDEX idx_tax_org (organization_id);

-- Payment Methods
ALTER TABLE payment_methods 
  ADD COLUMN organization_id INT NULL AFTER user_id,
  ADD INDEX idx_pm_org (organization_id);

-- ============================================================================
-- 3. Add soft-delete columns to clients (preparing for archived_clients drop)
-- ============================================================================
ALTER TABLE clients 
  ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER archived,
  ADD COLUMN archive_payload JSON NULL AFTER deleted_at,
  ADD INDEX idx_clients_deleted (deleted_at);

-- ============================================================================
-- 4. Normalize org_id → organization_id in receipts and related tables
-- ============================================================================
ALTER TABLE receipt_stores 
  CHANGE COLUMN org_id organization_id INT NOT NULL,
  DROP INDEX idx_store_org,
  ADD INDEX idx_store_org (organization_id);

ALTER TABLE receipts 
  CHANGE COLUMN org_id organization_id INT NOT NULL,
  DROP INDEX idx_receipt_org,
  ADD INDEX idx_receipt_org (organization_id);

ALTER TABLE form_categories 
  CHANGE COLUMN org_id organization_id INT NOT NULL,
  DROP INDEX idx_form_cat_org,
  ADD INDEX idx_form_cat_org (organization_id);
