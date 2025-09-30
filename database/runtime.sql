-- database/runtime.sql
-- Idempotent runtime migrations to ensure schema matches application needs.
-- These run at container startup (web) and are safe to re-run.

-- Use database (the web start script supplies -D, but this is harmless if present)
USE project_alpha;

-- Clients: add address fields if missing
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS address_line1 VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS address_line2 VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS city VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS state VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS postal VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS country VARCHAR(100) NULL;

-- Contracts: add discount/tax/totals if missing (used by contracts_create)
ALTER TABLE contracts
  ADD COLUMN IF NOT EXISTS discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  ADD COLUMN IF NOT EXISTS discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS total DECIMAL(12,2) NOT NULL DEFAULT 0;
