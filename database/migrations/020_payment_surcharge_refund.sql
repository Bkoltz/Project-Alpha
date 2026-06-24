-- Migration 020: Payment surcharge refund tracking
-- ============================================================================
-- Purpose: Track refunds of surcharges applied to debit/prepaid card payments
--          so the webhook can remain compliant with the Durbin Amendment.
--
-- Columns are nullable/defaulted for backward compatibility with existing rows.
-- ============================================================================

USE project_alpha;

ALTER TABLE payments
    ADD COLUMN surcharge_refunded TINYINT(1) NOT NULL DEFAULT 0 AFTER surcharge_paid,
    ADD COLUMN surcharge_refund_amount DECIMAL(10,2) NULL AFTER surcharge_refunded;

INSERT INTO migrations (filename, checksum) VALUES
    ('020_payment_surcharge_refund.sql', SHA2('020_payment_surcharge_refund.sql', 256))
ON DUPLICATE KEY UPDATE filename = filename;
