-- Migration 014: Drop Dead Tables (orphan tables with zero data and zero code references)
-- These tables exist in the DB but are NOT in init.sql and have no PHP references.
-- Safe to drop. The recurring_invoices tables are referenced in cron scripts which
-- are being removed as part of Task 2 (consolidate cron/ into src/). If cron scripts
-- are needed later, they must be rewritten to use the main invoices table with recurrence fields.
-- Date: 2026-06-16

USE project_alpha;

-- Tables with 0 rows, 0 PHP references (confirmed via grep):
DROP TABLE IF EXISTS contract_history;
DROP TABLE IF EXISTS contract_notes;
DROP TABLE IF EXISTS document_custom_field_values;
DROP TABLE IF EXISTS invoice_history;
DROP TABLE IF EXISTS notification_log;
DROP TABLE IF EXISTS notification_settings;
DROP TABLE IF EXISTS quote_history;
DROP TABLE IF EXISTS webhook_deliveries;

-- Tables referenced only in legacy cron/ scripts (being removed):
-- These had FKs: recurring_invoice_items -> recurring_invoices,
-- recurring_invoices -> clients, contracts, organizations, projects
DROP TABLE IF EXISTS recurring_invoice_items;
DROP TABLE IF EXISTS recurring_invoices;

-- Note: archived_clients and archived_entities are actively used
-- in src/controllers/client/clients_delete.php and clients_restore.php
-- They are intentionally kept.
