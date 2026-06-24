-- Migration 021: Add optional per-organization brand fields.
-- All columns are NULL (nullable) = backward compatible.
-- Existing orgs keep NULL values and fall back to global app_config branding.

ALTER TABLE organizations
    ADD COLUMN brand_name VARCHAR(150) NULL AFTER name,
    ADD COLUMN brand_logo_path VARCHAR(255) NULL AFTER brand_name,
    ADD COLUMN brand_from_name VARCHAR(150) NULL AFTER brand_logo_path,
    ADD COLUMN brand_from_email VARCHAR(255) NULL AFTER brand_from_name,
    ADD COLUMN brand_from_phone VARCHAR(50) NULL AFTER brand_from_email,
    ADD COLUMN brand_address_line1 VARCHAR(255) NULL AFTER brand_from_phone,
    ADD COLUMN brand_address_line2 VARCHAR(255) NULL AFTER brand_address_line1,
    ADD COLUMN brand_city VARCHAR(100) NULL AFTER brand_address_line2,
    ADD COLUMN brand_state VARCHAR(100) NULL AFTER brand_city,
    ADD COLUMN brand_postal VARCHAR(20) NULL AFTER brand_state;
