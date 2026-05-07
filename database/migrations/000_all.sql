-- database/migrations/000_all.sql
-- Master database schema file for project_alpha
-- 
-- This file sources all individual module files for modularity.
-- Run this file to initialize the entire database.
-- 
-- Module files:
--   008_documents_module.sql    - Quotes, contracts, invoices, signatures
--   009_auth_users_module.sql   - Users, auth, organizations, API keys, public links
--   010_financial_module.sql    - Payments, receipts, tax rates, item library
--   011_projects_clients_module.sql - Clients, projects, document settings
--   012_audit_system_module.sql - Audit logs, notifications, cron tracking

CREATE DATABASE IF NOT EXISTS project_alpha CHARACTER
SET
  utf8mb4 COLLATE utf8mb4_unicode_ci;

USE project_alpha;

-- Source all module definitions (dependency order matters)
-- Auth & Users must come first (other modules reference users/organizations)
SOURCE 009_auth_users_module.sql;

-- Documents module (quotes, contracts, invoices)
SOURCE 008_documents_module.sql;

-- Financial module (payments, receipts, taxes)
SOURCE 010_financial_module.sql;

-- Projects & Clients module
SOURCE 011_projects_clients_module.sql;

-- Audit & System module (logging, notifications, cron)
SOURCE 012_audit_system_module.sql;
