-- ============================================================================
-- CRON
-- ============================================================================

  IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    method VARCHAR(50) NULL,
    check_number VARCHAR(100) NULL,
    notes TEXT NULL,
    stripe_payment_intent_id VARCHAR(100) NULL,
    stripe_subscription_id VARCHAR(255) NULL,
    stripe_charge_id VARCHAR(255) NULL,
    auto_pay_attempt TINYINT (1) DEFAULT 0,
    payment_method_id INT NULL,
    status ENUM ('pending', 'succeeded', 'failed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payments_stripe_sub (stripe_subscription_id),
    INDEX idx_payments_auto_pay (auto_pay_attempt),
    CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    INDEX idx_payments_invoice (invoice_id),
    INDEX idx_payments_status (status)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
