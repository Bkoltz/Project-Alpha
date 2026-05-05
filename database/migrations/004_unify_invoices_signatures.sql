-- Migration 004: Unify Invoices and Signatures
-- Drops on_demand_invoices, unifies signatures table, updates invoice FKs
-- Date: 2026-05-04

USE project_alpha;

-- ============================================================================
-- STEP 1: Add contract_type and on-demand columns to invoices
-- ============================================================================
ALTER TABLE invoices 
  ADD COLUMN contract_type ENUM('regular', 'long_term', 'on_demand') NULL AFTER contract_id,
  ADD COLUMN on_demand_invoice_number INT NULL AFTER doc_number,
  ADD COLUMN generated_at TIMESTAMP NULL AFTER created_at,
  ADD COLUMN on_demand_notes TEXT NULL AFTER scope,
  ADD COLUMN organization_id INT NULL AFTER project_id,
  ADD INDEX idx_invoices_contract_type (contract_type),
  ADD INDEX idx_invoices_org (organization_id);

-- ============================================================================
-- STEP 2: Migrate invoice parentage using contract mapping
-- ============================================================================

-- Set contract_type for regular contracts
UPDATE invoices i
  JOIN _contract_id_mapping m ON m.old_table = 'contracts' AND m.old_id = i.contract_id
  SET i.contract_type = 'regular'
  WHERE i.contract_id IS NOT NULL;

-- Migrate long_term_contract_id references
UPDATE invoices i
  JOIN _contract_id_mapping m ON m.old_table = 'long_term_contracts' AND m.old_id = i.long_term_contract_id
  SET i.contract_id = m.new_id, i.contract_type = 'long_term'
  WHERE i.long_term_contract_id IS NOT NULL;

-- Migrate on_demand_contract_id references
UPDATE invoices i
  JOIN _contract_id_mapping m ON m.old_table = 'on_demand_contracts' AND m.old_id = i.on_demand_contract_id
  SET i.contract_id = m.new_id, i.contract_type = 'on_demand'
  WHERE i.on_demand_contract_id IS NOT NULL;

-- Migrate on_demand_invoices data into invoices columns
UPDATE invoices i
  JOIN on_demand_invoices odi ON odi.invoice_id = i.id
  SET i.on_demand_invoice_number = odi.invoice_number,
      i.generated_at = odi.generated_at,
      i.on_demand_notes = odi.notes;

-- ============================================================================
-- STEP 3: Drop old FK columns and on_demand_invoices table
-- ============================================================================
ALTER TABLE invoices 
  DROP FOREIGN KEY fk_invoices_contract,
  DROP COLUMN long_term_contract_id,
  DROP COLUMN on_demand_contract_id,
  DROP INDEX idx_invoices_ltc,
  DROP INDEX idx_invoices_odc;

-- Re-add FK to unified contracts table
ALTER TABLE invoices
  ADD CONSTRAINT fk_invoices_contract_new FOREIGN KEY (contract_id) REFERENCES contracts_new(id) ON DELETE SET NULL;

-- Drop parent_contract_type (replaced by contract_type)
ALTER TABLE invoices DROP COLUMN parent_contract_type;

-- ============================================================================
-- STEP 4: Migrate on_demand_invoices status to invoices
-- ============================================================================
-- Note: on_demand_invoices had its own status enum. We keep invoices.status as the canonical status.
-- The on_demand_invoices.status was mostly 'draft'/'sent'/'paid' which maps to invoices 'unpaid'/'paid'.
-- This is handled in application layer if needed.

-- Drop on_demand_invoices
DROP TABLE IF EXISTS on_demand_invoices;

-- ============================================================================
-- STEP 5: Create unified signatures table
-- ============================================================================
CREATE TABLE contract_signatures_new (
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
  FOREIGN KEY (contract_id) REFERENCES contracts_new(id) ON DELETE CASCADE,
  INDEX idx_sig_contract (contract_id),
  INDEX idx_sig_signed (signed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate regular contract signatures
INSERT INTO contract_signatures_new (contract_id, signer_title, signer_name, signer_email, signature_data, signed_at, display_order, is_required, created_at)
SELECT contract_id, signer_title, signer_name, signer_email, signature_data, signed_at, display_order, is_required, created_at
FROM contract_signatures WHERE contract_id IS NOT NULL;

-- Migrate long-term contract signatures
INSERT INTO contract_signatures_new (contract_id, signer_title, signer_name, signer_email, signature_data, signed_at, display_order, is_required, created_at)
SELECT m.new_id, signer_title, signer_name, signer_email, signature_data, signed_at, display_order, is_required, created_at
FROM contract_signatures cs
JOIN _contract_id_mapping m ON m.old_table = 'long_term_contracts' AND m.old_id = cs.long_term_contract_id
WHERE cs.long_term_contract_id IS NOT NULL;

-- Migrate on-demand contract signatures
INSERT INTO contract_signatures_new (contract_id, signer_title, signer_name, signer_email, signature_data, signed_at, display_order, is_required, created_at)
SELECT m.new_id, signer_title, signer_name, signer_email, signature_data, signed_at, display_order, is_required, created_at
FROM contract_signatures cs
JOIN _contract_id_mapping m ON m.old_table = 'on_demand_contracts' AND m.old_id = cs.on_demand_contract_id
WHERE cs.on_demand_contract_id IS NOT NULL;
