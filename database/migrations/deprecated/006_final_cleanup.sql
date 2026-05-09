-- Migration 006: Final Cleanup - Rename Tables, Drop Old, Normalize
-- Final step: swap new tables for old, drop old tables, clean up
-- Date: 2026-05-04

USE project_alpha;

-- ============================================================================
-- STEP 1: Rename old tables to _old suffix (for rollback safety)
-- ============================================================================
RENAME TABLE contracts TO contracts_old;
RENAME TABLE contract_items TO contract_items_old;
RENAME TABLE long_term_contracts TO long_term_contracts_old;
RENAME TABLE long_term_contract_items TO long_term_contract_items_old;
RENAME TABLE on_demand_contracts TO on_demand_contracts_old;
RENAME TABLE on_demand_contract_items TO on_demand_contract_items_old;
RENAME TABLE contract_signatures TO contract_signatures_old;

-- ============================================================================
-- STEP 2: Rename new tables to canonical names
-- ============================================================================
RENAME TABLE contracts_new TO contracts;
RENAME TABLE contract_items_new TO contract_items;
RENAME TABLE contract_signatures_new TO contract_signatures;

-- ============================================================================
-- STEP 3: Add FK from invoices to unified contracts (was temporarily dropped)
-- ============================================================================
-- Already added in migration 004 as fk_invoices_contract_new
-- Rename it to canonical name
ALTER TABLE invoices DROP FOREIGN KEY fk_invoices_contract_new;
ALTER TABLE invoices ADD CONSTRAINT fk_invoices_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL;

-- ============================================================================
-- STEP 4: Update project_documents enum and migrate types
-- ============================================================================
-- The document_type enum already has 'contract' which covers all unified contract types
-- Update any rows that referenced long_term_contract or on_demand_contract
UPDATE project_documents SET document_type = 'contract' WHERE document_type IN ('long_term_contract', 'on_demand_contract');

-- ============================================================================
-- STEP 5: Clean up mapping table
-- ============================================================================
DROP TABLE IF EXISTS _contract_id_mapping;

-- ============================================================================
-- STEP 6: Verify no orphaned FKs exist
-- ============================================================================
-- These checks can be run manually if desired:
-- SELECT COUNT(*) FROM invoices WHERE contract_id IS NOT NULL AND contract_id NOT IN (SELECT id FROM contracts);
-- SELECT COUNT(*) FROM contract_items WHERE contract_id NOT IN (SELECT id FROM contracts);
-- SELECT COUNT(*) FROM contract_signatures WHERE contract_id NOT IN (SELECT id FROM contracts);
