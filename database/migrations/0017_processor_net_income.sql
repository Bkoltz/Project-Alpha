-- Processor-neutral net income tracking for Stripe and future processors.
-- Migrations must not call processor APIs; historical Stripe fee/net data is
-- repaired by cron/admin actions after deploy.

ALTER TABLE payments
    ADD COLUMN processor_net_amount DECIMAL(12,2) NULL AFTER processor_fee_amount,
    ADD COLUMN processor_fee_policy VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER processor_net_amount,
    ADD COLUMN processor_fee_source VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER processor_fee_policy;

ALTER TABLE project_invoice_payments
    ADD COLUMN processor_provider VARCHAR(50) NULL AFTER payment_method,
    ADD COLUMN processor_payment_id VARCHAR(255) NULL AFTER processor_provider,
    ADD COLUMN processor_gross_amount DECIMAL(12,2) NULL AFTER processor_payment_id,
    ADD COLUMN processor_fee_amount DECIMAL(12,2) NULL AFTER processor_gross_amount,
    ADD COLUMN processor_net_amount DECIMAL(12,2) NULL AFTER processor_fee_amount,
    ADD COLUMN processor_fee_policy VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER processor_net_amount,
    ADD COLUMN processor_fee_source VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER processor_fee_policy,
    ADD INDEX idx_project_payment_processor (processor_provider, processor_payment_id);
