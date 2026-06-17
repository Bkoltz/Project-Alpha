-- Migration 015: Final Schema Sync
-- ============================================================================
-- Purpose: Reconcile drift between init.sql (single source of truth) and the
--          numbered migration chain 001..014.
--
-- Methodology: Built two fresh MySQL 8 databases empirically — one from
--   init.sql, one from applying migrations 001..014 in canonical order — and
--   diffed information_schema (COLUMNS, STATISTICS, KEY_COLUMN_USAGE).
--
-- Result of that diff: the ONLY columns present in init.sql but missing from
--   the migration-built schema are nine columns on `audit_schedules`. There
--   were NO missing indexes and NO missing foreign keys. This migration adds
--   exactly those nine columns, idempotently.
--
-- NOTE ON IDEMPOTENCY: MySQL 8 does NOT support
--   `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` (that is MariaDB-only syntax and
--   raises ERROR 1064 on MySQL). To stay safe/re-runnable we use a small helper
--   stored procedure that checks information_schema and only adds a column when
--   it is absent. The procedure is dropped again at the end.
--
-- IMPORTANT — drift this migration does NOT touch (needs human review, see
--   notes at bottom): audit_schedules has additional type/enum/extra-column
--   differences that can only be reconciled with destructive DROP/MODIFY
--   statements. Those are intentionally left out of this safe sync.
--
-- Date: 2026-06-17
-- ============================================================================

USE project_alpha;

-- ----------------------------------------------------------------------------
-- Idempotency helper: add a column only when it does not already exist.
-- ----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _add_col_if_absent;
DELIMITER $$
CREATE PROCEDURE _add_col_if_absent(
    IN p_table  VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_ddl    TEXT          -- the column definition + position, e.g. "VARCHAR(50) NOT NULL DEFAULT 'x' AFTER y"
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_column
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_ddl);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- ----------------------------------------------------------------------------
-- audit_schedules: add nine columns defined in init.sql (lines 855-863) that
-- the migration chain never created. Types/defaults copied verbatim from
-- init.sql. Each call is a no-op if the column already exists.
-- ----------------------------------------------------------------------------

-- init.sql: date_range_type VARCHAR(50) NOT NULL DEFAULT 'current_year'
CALL _add_col_if_absent('audit_schedules', 'date_range_type',
    "VARCHAR(50) NOT NULL DEFAULT 'current_year' AFTER frequency");

-- init.sql: email_addresses TEXT NOT NULL
-- NOTE: TEXT cannot carry a literal default in MySQL; init.sql declares it
-- NOT NULL with no default. Existing rows (if any) get '' to satisfy NOT NULL.
CALL _add_col_if_absent('audit_schedules', 'email_addresses',
    "TEXT NOT NULL AFTER date_range_type");

-- init.sql: include_invoices TINYINT(1) NOT NULL DEFAULT 1
CALL _add_col_if_absent('audit_schedules', 'include_invoices',
    "TINYINT(1) NOT NULL DEFAULT 1 AFTER email_addresses");

-- init.sql: include_unpaid_invoices TINYINT(1) NOT NULL DEFAULT 0
CALL _add_col_if_absent('audit_schedules', 'include_unpaid_invoices',
    "TINYINT(1) NOT NULL DEFAULT 0 AFTER include_invoices");

-- init.sql: include_contracts TINYINT(1) NOT NULL DEFAULT 0
CALL _add_col_if_absent('audit_schedules', 'include_contracts',
    "TINYINT(1) NOT NULL DEFAULT 0 AFTER include_unpaid_invoices");

-- init.sql: include_quotes TINYINT(1) NOT NULL DEFAULT 0
CALL _add_col_if_absent('audit_schedules', 'include_quotes',
    "TINYINT(1) NOT NULL DEFAULT 0 AFTER include_contracts");

-- init.sql: generate_csv TINYINT(1) NOT NULL DEFAULT 1
CALL _add_col_if_absent('audit_schedules', 'generate_csv',
    "TINYINT(1) NOT NULL DEFAULT 1 AFTER include_quotes");

-- init.sql: include_pdfs TINYINT(1) NOT NULL DEFAULT 0
CALL _add_col_if_absent('audit_schedules', 'include_pdfs',
    "TINYINT(1) NOT NULL DEFAULT 0 AFTER generate_csv");

-- init.sql: options JSON NULL
CALL _add_col_if_absent('audit_schedules', 'options',
    "JSON NULL AFTER include_pdfs");

-- Clean up the helper.
DROP PROCEDURE IF EXISTS _add_col_if_absent;

-- ----------------------------------------------------------------------------
-- Indexes: empirical diff found NO missing indexes. audit_schedules already
-- has idx_audit_sched_org, idx_audit_sched_active, idx_audit_sched_next in
-- both schemas. Nothing to add.
--
-- Foreign keys: empirical diff found NO missing foreign keys. Nothing to add.
-- ----------------------------------------------------------------------------

-- Record this migration as applied (matches the 013_migrations_tracking pattern).
INSERT INTO migrations (filename, checksum) VALUES
    ('015_final_schema_sync.sql', 'schema_sync_audit_schedules_cols')
ON DUPLICATE KEY UPDATE filename = filename;

-- ============================================================================
-- REMAINING DRIFT — NOT auto-fixed here (requires human decision, destructive):
--
--   1. audit_schedules.frequency ENUM differs:
--        init.sql:    ENUM('weekly','monthly','quarterly','annually')
--        migrations:  ENUM('daily','weekly','monthly','quarterly','yearly')
--      Reconciling requires ALTER ... MODIFY (could invalidate existing rows
--      using 'daily'/'yearly').
--
--   2. Datetime type mismatch on next_run_at, last_run_at, created_at,
--      updated_at:  init.sql uses DATETIME; migrations use TIMESTAMP.
--      Reconciling requires ALTER ... MODIFY.
--
--   3. Columns audit_schedules.name (VARCHAR(150) NOT NULL) and
--      audit_schedules.description (TEXT) exist in the migration schema but
--      NOT in init.sql. Removing them needs DROP COLUMN (destructive); adding
--      them to init.sql may be the correct fix instead.
--
--   4. Tables present in the migration schema but absent from init.sql:
--        archived_clients, archived_entities  (actively used by
--        src/controllers/client/clients_delete.php & clients_restore.php),
--        migrations  (created by 013_migrations_tracking.sql).
--      These are legitimate and should be ADDED TO init.sql so it is once
--      again the true single source of truth. This migration leaves them in
--      place (does not drop them).
-- ============================================================================
