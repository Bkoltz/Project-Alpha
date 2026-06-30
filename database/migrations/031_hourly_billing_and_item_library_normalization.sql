-- Migration 031: Hourly billing support and item library normalization
-- Adds document-level hourly billing metadata and repairs older item_library schemas
-- that used `name` while current application code expects `item_name`.

USE project_alpha;

-- Normalize item_library name/item_name drift across production installs.
SET @has_item_name := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'item_library' AND column_name = 'item_name'
);
SET @has_name := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'item_library' AND column_name = 'name'
);

SET @sql := IF(@has_item_name = 0 AND @has_name > 0,
    'ALTER TABLE item_library CHANGE COLUMN name item_name VARCHAR(255) NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_item_name := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'item_library' AND column_name = 'item_name'
);
SET @sql := IF(@has_item_name = 0,
    'ALTER TABLE item_library ADD COLUMN item_name VARCHAR(255) NOT NULL DEFAULT '''' AFTER organization_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_name := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'item_library' AND column_name = 'name'
);
SET @sql := IF(@has_name > 0,
    'UPDATE item_library SET item_name = name WHERE (item_name IS NULL OR item_name = '''') AND name IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'item_library' AND index_name = 'idx_item_lib_item_name'
);
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE item_library ADD INDEX idx_item_lib_item_name (item_name)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Document-level billing mode.
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'quotes' AND column_name = 'billing_mode'
);
SET @has_anchor := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'quotes' AND column_name = 'quote_type'
);
SET @sql := IF(@has_col = 0,
    IF(@has_anchor > 0,
        'ALTER TABLE quotes ADD COLUMN billing_mode ENUM(''fixed'',''hourly'') NOT NULL DEFAULT ''fixed'' AFTER quote_type',
        'ALTER TABLE quotes ADD COLUMN billing_mode ENUM(''fixed'',''hourly'') NOT NULL DEFAULT ''fixed'''
    ),
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'contracts' AND column_name = 'billing_mode'
);
SET @has_anchor := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'contracts' AND column_name = 'contract_type'
);
SET @sql := IF(@has_col = 0,
    IF(@has_anchor > 0,
        'ALTER TABLE contracts ADD COLUMN billing_mode ENUM(''fixed'',''hourly'') NOT NULL DEFAULT ''fixed'' AFTER contract_type',
        'ALTER TABLE contracts ADD COLUMN billing_mode ENUM(''fixed'',''hourly'') NOT NULL DEFAULT ''fixed'''
    ),
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'billing_mode'
);
SET @has_anchor := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'invoice_type'
);
SET @sql := IF(@has_col = 0,
    IF(@has_anchor > 0,
        'ALTER TABLE invoices ADD COLUMN billing_mode ENUM(''fixed'',''hourly'') NOT NULL DEFAULT ''fixed'' AFTER invoice_type',
        'ALTER TABLE invoices ADD COLUMN billing_mode ENUM(''fixed'',''hourly'') NOT NULL DEFAULT ''fixed'''
    ),
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Item billing units for document line items.
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'quote_items' AND column_name = 'billing_unit'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE quote_items ADD COLUMN billing_unit ENUM(''each'',''hour'') NOT NULL DEFAULT ''each'' AFTER line_total',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'contract_items' AND column_name = 'billing_unit'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE contract_items ADD COLUMN billing_unit ENUM(''each'',''hour'') NOT NULL DEFAULT ''each'' AFTER line_total',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoice_items' AND column_name = 'billing_unit'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE invoice_items ADD COLUMN billing_unit ENUM(''each'',''hour'') NOT NULL DEFAULT ''each'' AFTER line_total',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Time entry links to jobs, contracts, invoices, and hourly catalog services.
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'project_code'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE time_entries ADD COLUMN project_code VARCHAR(64) NULL AFTER project_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'contract_id'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE time_entries ADD COLUMN contract_id INT NULL AFTER project_code',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'invoice_id'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE time_entries ADD COLUMN invoice_id INT NULL AFTER contract_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND column_name = 'service_item_id'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE time_entries ADD COLUMN service_item_id INT NULL AFTER invoice_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND index_name = 'idx_time_entries_project_code'
);
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE time_entries ADD INDEX idx_time_entries_project_code (project_code)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND index_name = 'idx_time_entries_contract'
);
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE time_entries ADD INDEX idx_time_entries_contract (contract_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'time_entries' AND index_name = 'idx_time_entries_invoice'
);
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE time_entries ADD INDEX idx_time_entries_invoice (invoice_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
