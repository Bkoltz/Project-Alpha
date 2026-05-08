-- Migration: Update payments table for Stripe webhook support
-- Run this on existing databases to add required columns
-- Date: 2026-05-07

USE project_alpha;

-- Check if we need to rename payment_method to method
SET @exists = (SELECT COUNT(*) FROM information_schema.columns 
               WHERE table_schema = 'project_alpha' 
               AND table_name = 'payments' 
               AND column_name = 'payment_method');

SET @exists_method = (SELECT COUNT(*) FROM information_schema.columns 
                        WHERE table_schema = 'project_alpha' 
                        AND table_name = 'payments' 
                        AND column_name = 'method');

-- Rename payment_method to method if needed
SET @sql = IF(@exists > 0 AND @exists_method = 0,
    'ALTER TABLE payments CHANGE COLUMN payment_method method ENUM(\"cash\",\"check\",\"card\",\"bank_transfer\",\"stripe\",\"other\") NOT NULL DEFAULT \"cash\"',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add stripe_session_id if not exists
SET @exists_ss = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = 'project_alpha' 
                   AND table_name = 'payments' 
                   AND column_name = 'stripe_session_id');

SET @sql = IF(@exists_ss = 0,
    'ALTER TABLE payments ADD COLUMN stripe_session_id VARCHAR(255) NULL AFTER notes',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add stripe_payment_intent_id if not exists
SET @exists_spi = (SELECT COUNT(*) FROM information_schema.columns 
                    WHERE table_schema = 'project_alpha' 
                    AND table_name = 'payments' 
                    AND column_name = 'stripe_payment_intent_id');

SET @sql = IF(@exists_spi = 0,
    'ALTER TABLE payments ADD COLUMN stripe_payment_intent_id VARCHAR(255) NULL AFTER stripe_session_id',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add auto_pay_attempt if not exists
SET @exists_ap = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = 'project_alpha' 
                   AND table_name = 'payments' 
                   AND column_name = 'auto_pay_attempt');

SET @sql = IF(@exists_ap = 0,
    'ALTER TABLE payments ADD COLUMN auto_pay_attempt TINYINT(1) NOT NULL DEFAULT 0 AFTER stripe_payment_intent_id',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add status if not exists
SET @exists_status = (SELECT COUNT(*) FROM information_schema.columns 
                       WHERE table_schema = 'project_alpha' 
                       AND table_name = 'payments' 
                       AND column_name = 'status');

SET @sql = IF(@exists_status = 0,
    'ALTER TABLE payments ADD COLUMN status ENUM(\"succeeded\",\"failed\",\"pending\") NOT NULL DEFAULT \"succeeded\" AFTER auto_pay_attempt',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add indexes for Stripe lookups
CREATE INDEX IF NOT EXISTS idx_payments_stripe_session ON payments(stripe_session_id);
CREATE INDEX IF NOT EXISTS idx_payments_stripe_pi ON payments(stripe_payment_intent_id);

-- Verify final schema
SHOW COLUMNS FROM payments;
