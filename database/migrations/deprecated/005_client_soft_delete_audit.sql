-- Migration 005: Client Soft Deletes and Audit Consolidation
-- Migrates archived_clients to soft deletes, merges activity_log into system_audit
-- Date: 2026-05-04

USE project_alpha;

-- ============================================================================
-- STEP 1: Migrate archived_clients to clients with soft delete
-- ============================================================================

-- Insert archived clients back into clients table with deleted_at set
INSERT INTO clients (
  name, email, phone, organization_id, notes,
  address_line1, address_line2, city, state, postal, country,
  archived, deleted_at, archive_payload, created_at
)
SELECT
  name, email, phone, organization_id, notes,
  address_line1, address_line2, city, state, postal, country,
  1, archived_at, JSON_OBJECT(
    'original_id', client_id,
    'archived_at', archived_at,
    'source', 'archived_clients_migration'
  ), created_at
FROM archived_clients;

-- ============================================================================
-- STEP 2: Drop archived_clients and archived_entities
-- ============================================================================
DROP TABLE IF EXISTS archived_clients;
DROP TABLE IF EXISTS archived_entities;

-- ============================================================================
-- STEP 3: Merge activity_log into system_audit
-- ============================================================================

-- Add activity_log columns to system_audit if not present
ALTER TABLE system_audit
  ADD COLUMN IF NOT EXISTS document_type VARCHAR(20) NULL AFTER payload,
  ADD COLUMN IF NOT EXISTS document_id INT NULL AFTER document_type,
  ADD COLUMN IF NOT EXISTS client_id INT NULL AFTER document_id,
  ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER message,
  ADD COLUMN IF NOT EXISTS user_agent TEXT NULL AFTER ip,
  ADD COLUMN IF NOT EXISTS organization_id INT NULL AFTER actor_id,
  ADD INDEX idx_audit_doc (document_type, document_id),
  ADD INDEX idx_audit_client (client_id),
  ADD INDEX idx_audit_org (organization_id);

-- Migrate activity_log data into system_audit
INSERT INTO system_audit (
  level, category, actor_type, actor_id, organization_id, ip, user_agent,
  message, payload, document_type, document_id, client_id, description, created_at
)
SELECT
  'info', event_type, 'user', NULL, NULL, ip_address, user_agent,
  description, metadata, document_type, document_id, client_id, description, created_at
FROM activity_log;

-- ============================================================================
-- STEP 4: Drop old activity_log table
-- ============================================================================
DROP TABLE IF EXISTS activity_log;
