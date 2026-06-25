-- Migration 024: Add created_by column to scoped tables for record-level member scoping.
-- Idempotent: uses a stored procedure with information_schema checks. Safe on every boot.
-- Tables: quotes, contracts, invoices, clients, projects.

DELIMITER //
CREATE PROCEDURE _add_col_if_missing(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_def TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL _add_col_if_missing('quotes',   'created_by', 'INT NULL AFTER organization_id');
CALL _add_col_if_missing('contracts','created_by', 'INT NULL AFTER organization_id');
CALL _add_col_if_missing('invoices', 'created_by', 'INT NULL AFTER organization_id');
CALL _add_col_if_missing('clients',  'created_by', 'INT NULL AFTER organization_id');
CALL _add_col_if_missing('projects', 'created_by', 'INT NULL AFTER organization_id');

DROP PROCEDURE IF EXISTS _add_col_if_missing;

-- Note: No user_id column exists on these scoped tables in the current schema,
-- so record-level created_by values must be populated by application code or a
-- later targeted backfill once a creator source is defined.
