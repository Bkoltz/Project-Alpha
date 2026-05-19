-- Migration 007: Migrate and Drop Old Tables
-- Migrates remaining signature data, then drops legacy tables
-- Date: 2026-05-05

USE project_alpha;

-- ============================================================================
-- STEP 1: Migrate remaining contract signatures from old table
-- ============================================================================
-- Old table has: contract_id, long_term_contract_id, on_demand_contract_id, signer_title, signer_name, signer_email, signature_data, signed_at, display_order, is_required
-- New table has: contract_id, contract_type, client_signature, admin_signature, client_signed_at, admin_signed_at

INSERT INTO contract_signatures (contract_id, contract_type, client_signature, admin_signature, client_signed_at, admin_signed_at)
SELECT 
    COALESCE(cs_old.contract_id, cs_old.long_term_contract_id, cs_old.on_demand_contract_id) as contract_id,
    CASE 
        WHEN cs_old.contract_id IS NOT NULL THEN 'regular'
        WHEN cs_old.long_term_contract_id IS NOT NULL THEN 'long_term'
        WHEN cs_old.on_demand_contract_id IS NOT NULL THEN 'on_demand'
        ELSE 'regular'
    END as contract_type,
    cs_old.signature_data as client_signature,
    NULL as admin_signature,
    cs_old.signed_at as client_signed_at,
    NULL as admin_signed_at
FROM contract_signatures_old cs_old
WHERE COALESCE(cs_old.contract_id, cs_old.long_term_contract_id, cs_old.on_demand_contract_id) IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM contract_signatures cs 
      WHERE cs.contract_id = COALESCE(cs_old.contract_id, cs_old.long_term_contract_id, cs_old.on_demand_contract_id)
  );

-- ============================================================================
-- STEP 2: Drop all legacy tables
-- ============================================================================
DROP TABLE IF EXISTS contracts_old;
DROP TABLE IF EXISTS contract_items_old;
DROP TABLE IF EXISTS contract_signatures_old;
DROP TABLE IF EXISTS long_term_contracts_old;
DROP TABLE IF EXISTS long_term_contract_items_old;
DROP TABLE IF EXISTS on_demand_contracts_old;
DROP TABLE IF EXISTS on_demand_contract_items_old;
DROP TABLE IF EXISTS on_demand_invoices_old;
DROP TABLE IF EXISTS _contract_id_mapping;

-- ============================================================================
-- STEP 3: Drop legacy audit table (replaced by system_audit)
-- ============================================================================
DROP TABLE IF EXISTS activity_log;

-- ============================================================================
-- Verification: run after to confirm cleanup
-- SELECT table_name FROM information_schema.tables 
-- WHERE table_schema = 'project_alpha' AND table_name LIKE '%old';
