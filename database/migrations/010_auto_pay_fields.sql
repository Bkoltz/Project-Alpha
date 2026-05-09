-- ============================================================================
-- Migration: Add Auto-Pay Fields to Clients
-- ============================================================================
-- Adds Stripe customer ID and payment method storage for recurring auto-pay
-- ============================================================================

USE project_alpha;

-- Add auto-pay fields to clients table
ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS stripe_customer_id VARCHAR(255) NULL AFTER notes,
    ADD COLUMN IF NOT EXISTS stripe_payment_method_id VARCHAR(255) NULL AFTER stripe_customer_id,
    ADD COLUMN IF NOT EXISTS auto_pay_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER stripe_payment_method_id,
    ADD COLUMN IF NOT EXISTS auto_pay_setup_date TIMESTAMP NULL AFTER auto_pay_enabled,
    ADD INDEX IF NOT EXISTS idx_clients_stripe_customer (stripe_customer_id),
    ADD INDEX IF NOT EXISTS idx_clients_auto_pay (auto_pay_enabled);

-- Add surcharge tracking to invoices
ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS surcharge_amount DECIMAL(12, 2) NULL AFTER amount_paid,
    ADD COLUMN IF NOT EXISTS surcharge_type VARCHAR(20) NULL AFTER surcharge_amount,
    ADD COLUMN IF NOT EXISTS original_amount DECIMAL(12, 2) NULL AFTER surcharge_type,
    ADD COLUMN IF NOT EXISTS last_auto_pay_attempt TIMESTAMP NULL AFTER original_amount,
    ADD COLUMN IF NOT EXISTS type VARCHAR(50) NULL AFTER last_auto_pay_attempt;

-- ============================================================================
-- AUTO-PAY CRON JOB LOG
-- ============================================================================
CREATE TABLE IF NOT EXISTS auto_pay_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    invoice_id INT NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    status ENUM('success', 'failed', 'skipped') NOT NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auto_pay_client (client_id),
    INDEX idx_auto_pay_invoice (invoice_id),
    INDEX idx_auto_pay_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add payment method metadata tracking
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS surcharge_paid DECIMAL(12, 2) NULL AFTER amount,
    ADD COLUMN IF NOT EXISTS merchant_fee DECIMAL(12, 2) NULL AFTER surcharge_paid;
