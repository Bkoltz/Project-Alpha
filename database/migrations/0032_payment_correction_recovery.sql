-- Extend payment-allocation corrections to recover legacy local-only Stripe
-- refund entries and to reverse more than one duplicate manual payment.

SET @correction_reversed_ids_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'payment_corrections' AND column_name = 'reversed_payment_ids'
);
SET @sql := IF(@correction_reversed_ids_exists = 0, 'ALTER TABLE payment_corrections ADD COLUMN reversed_payment_ids JSON NULL AFTER reversed_payment_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @correction_cleared_refund_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'payment_corrections' AND column_name = 'cleared_local_refund_amount'
);
SET @sql := IF(@correction_cleared_refund_exists = 0, 'ALTER TABLE payment_corrections ADD COLUMN cleared_local_refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER source_voided', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @correction_verified_refund_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'payment_corrections' AND column_name = 'processor_refund_verified_amount'
);
SET @sql := IF(@correction_verified_refund_exists = 0, 'ALTER TABLE payment_corrections ADD COLUMN processor_refund_verified_amount DECIMAL(12,2) NULL AFTER cleared_local_refund_amount', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
