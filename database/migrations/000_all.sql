-- database/migrations/000_all.sql
-- Master database schema file for project_alpha
-- 
-- This file now sources all individual table files for modularity.
-- Run this file to initialize the entire database.
-- 
-- Individual files (for reference/rebuilding specific modules):
--   001_users_auth.sql       - Users, passwords, 2FA, trusted devices/IPs
--   002_api_management.sql   - API keys and usage tracking
--   003_organizations.sql    - Organization management
--   004_clients.sql          - Clients and archived entities
--   005_links.sql            - Link resolver storage
--   006_projects.sql         - Projects and project documents
--   007_quotes.sql           - Quotes and quote items
--   008_contracts.sql        - Contracts, signatures, notes
--   009_invoices.sql         - Invoices, items, payments
--   010_financial.sql        - Financial records and audit
--   011_settings.sql         - App config, link resolver config
--   012_cron.sql             - Cron job runs
--   014_documents.sql        - Document templates, settings, custom fields
--   015_system.sql           - Audit logs, notifications, webhooks, jobs

CREATE DATABASE IF NOT EXISTS project_alpha CHARACTER
SET
  utf8mb4 COLLATE utf8mb4_unicode_ci;

USE project_alpha;

-- Source all table definitions in dependency order
SOURCE 001_users_auth.sql;
SOURCE 002_api_management.sql;
SOURCE 003_organizations.sql;
SOURCE 004_clients.sql;
SOURCE 005_links.sql;
SOURCE 006_projects.sql;
SOURCE 007_quotes.sql;
SOURCE 008_contracts.sql;
SOURCE 009_invoices.sql;
SOURCE 010_financial.sql;
SOURCE 011_settings.sql;
SOURCE 012_cron.sql;
SOURCE 014_documents.sql;
SOURCE 015_system.sql;
