-- Preserve accounting corrections without misclassifying them as refunds.
-- A reversed payment is a mistaken local entry; it does not imply money was
-- returned to the client or that a processor refund occurred.

ALTER TABLE payments
    MODIFY COLUMN status ENUM('succeeded','failed','pending','reversed') NOT NULL DEFAULT 'succeeded';

SET @payment_reversed_at_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'reversed_at'
);
SET @sql := IF(@payment_reversed_at_exists = 0, 'ALTER TABLE payments ADD COLUMN reversed_at TIMESTAMP NULL AFTER status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @payment_reversed_by_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'reversed_by'
);
SET @sql := IF(@payment_reversed_by_exists = 0, 'ALTER TABLE payments ADD COLUMN reversed_by INT NULL AFTER reversed_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @payment_reversal_reason_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'reversal_reason'
);
SET @sql := IF(@payment_reversal_reason_exists = 0, 'ALTER TABLE payments ADD COLUMN reversal_reason VARCHAR(500) NULL AFTER reversed_by', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS payment_corrections (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    moved_payment_id INT NULL,
    reversed_payment_id INT NULL,
    source_invoice_id INT NULL,
    target_invoice_id INT NULL,
    corrected_by INT NULL,
    source_voided TINYINT(1) NOT NULL DEFAULT 0,
    reason VARCHAR(500) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment_corrections_moved (moved_payment_id),
    INDEX idx_payment_corrections_reversed (reversed_payment_id),
    INDEX idx_payment_corrections_source (source_invoice_id),
    INDEX idx_payment_corrections_target (target_invoice_id),
    INDEX idx_payment_corrections_created (created_at),
    CONSTRAINT fk_payment_correction_moved FOREIGN KEY (moved_payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_correction_reversed FOREIGN KEY (reversed_payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_correction_source FOREIGN KEY (source_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_correction_target FOREIGN KEY (target_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_correction_user FOREIGN KEY (corrected_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
