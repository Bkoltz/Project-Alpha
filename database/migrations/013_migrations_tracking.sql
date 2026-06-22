-- Migration 013: Migrations Tracking Table
-- Creates the migration tracking infrastructure for future schema changes.
-- Also records all previously-applied migrations as already run.
-- Date: 2026-06-16

USE project_alpha;

-- ============================================================================
-- MIGRATIONS TRACKING TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    checksum VARCHAR(64) NULL,
    INDEX idx_migrations_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- RECORD ALL PREVIOUSLY APPLIED MIGRATIONS
-- These migrations were already applied during prior schema setup.
-- Recording them prevents re-runs and enables future incremental migrations.
-- ============================================================================
INSERT INTO migrations (filename, checksum) VALUES
    ('001_auth_module.sql', 'applied_prior_to_tracking'),
    ('002_organizations_module.sql', 'applied_prior_to_tracking'),
    ('003_projects_clients_module.sql', 'applied_prior_to_tracking'),
    ('004_documents_module.sql', 'applied_prior_to_tracking'),
    ('005_financial_module.sql', 'applied_prior_to_tracking'),
    ('006_audit_system_module.sql', 'applied_prior_to_tracking'),
    ('007_migrate_and_drop_old_tables.sql', 'applied_prior_to_tracking'),
    ('007_public_links_module.sql', 'applied_prior_to_tracking'),
    ('008_documents_module.sql', 'applied_prior_to_tracking'),
    ('008_seed_data.sql', 'applied_prior_to_tracking'),
    ('009_auth_users_module.sql', 'applied_prior_to_tracking'),
    ('010_financial_module.sql', 'applied_prior_to_tracking'),
    ('011_projects_clients_module.sql', 'applied_prior_to_tracking'),
    ('012_audit_system_module.sql', 'applied_prior_to_tracking')
ON DUPLICATE KEY UPDATE filename = filename;
