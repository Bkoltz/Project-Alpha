-- database/migrations/017_vendors.sql
-- Extend receipt_stores into a full vendors table

-- Add columns to receipt_stores
ALTER TABLE receipt_stores
    ADD COLUMN email VARCHAR(255) NULL DEFAULT NULL AFTER name,
    ADD COLUMN phone VARCHAR(50) NULL DEFAULT NULL AFTER email,
    ADD COLUMN website VARCHAR(255) NULL DEFAULT NULL AFTER phone,
    ADD COLUMN tax_id VARCHAR(50) NULL DEFAULT NULL AFTER website,
    ADD COLUMN default_category_id INT NULL DEFAULT NULL AFTER tax_id,
    ADD COLUMN notes TEXT NULL DEFAULT NULL AFTER default_category_id,
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER notes;

-- Add FK for default_category_id
ALTER TABLE receipt_stores
    ADD CONSTRAINT fk_store_default_cat FOREIGN KEY (default_category_id) REFERENCES expense_categories(id) ON DELETE SET NULL;

-- Rename table
RENAME TABLE receipt_stores TO vendors;