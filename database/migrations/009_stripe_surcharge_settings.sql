-- ============================================================================
-- Migration: Add Stripe Surcharge Settings
-- ============================================================================
-- Adds fields to app_config for configuring credit card surcharge fees
-- ============================================================================

USE project_alpha;

-- Check if app_config table exists and add surcharge fields
-- These are stored as JSON in the app_config file, but we document them here
-- for reference and future database-backed settings.

-- The following settings will be added to app_config.json:
-- 
-- stripe_surcharge_enabled (bool) - Whether surcharges are active
-- stripe_surcharge_percent (decimal) - Processing fee percentage (e.g., 2.9)
-- stripe_surcharge_fixed (decimal) - Fixed fee amount in cents (e.g., 0.30)
-- stripe_surcharge_type (enum) - Who pays: 'merchant', 'split', 'client'
-- stripe_surcharge_split_percent (decimal) - How much client pays when split (e.g., 50)
--
-- When client pays any portion:
--   surcharge_amount = (invoice_total * percent/100) + fixed_fee
--   client_portion = surcharge_amount * (split_percent / 100)
--   new_invoice_total = invoice_total + client_portion

-- Note: These settings are managed via the Settings > Billing tab UI
-- and stored in the app_config.json file (not database columns)
