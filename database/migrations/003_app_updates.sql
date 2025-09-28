-- database/migrations/003_app_updates.sql
-- Extend clients with address fields; extend contracts with discount/tax/totals

ALTER TABLE clients
  ADD COLUMN address_line1 VARCHAR(200) NULL,
  ADD COLUMN address_line2 VARCHAR(200) NULL,
  ADD COLUMN city VARCHAR(100) NULL,
  ADD COLUMN state VARCHAR(100) NULL,
  ADD COLUMN postal VARCHAR(20) NULL,
  ADD COLUMN country VARCHAR(100) NULL;

ALTER TABLE contracts
  ADD COLUMN discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  ADD COLUMN discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
  ADD COLUMN tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  ADD COLUMN subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  ADD COLUMN total DECIMAL(12,2) NOT NULL DEFAULT 0;