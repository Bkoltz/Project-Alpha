-- Processor-agnostic standalone payment imports.

ALTER TABLE payments MODIFY COLUMN client_id INT NULL;
ALTER TABLE payments MODIFY COLUMN payment_method VARCHAR(50) NOT NULL DEFAULT 'cash';

ALTER TABLE payments
    ADD COLUMN processor_provider VARCHAR(50) NULL AFTER payment_method,
    ADD COLUMN processor_payment_id VARCHAR(255) NULL AFTER processor_provider,
    ADD COLUMN processor_transaction_id BIGINT NULL AFTER processor_payment_id,
    ADD COLUMN processor_gross_amount DECIMAL(12,2) NULL AFTER processor_transaction_id,
    ADD COLUMN processor_fee_amount DECIMAL(12,2) NULL AFTER processor_gross_amount,
    ADD UNIQUE KEY uq_payments_processor_payment (processor_provider, processor_payment_id),
    ADD INDEX idx_payments_processor_transaction (processor_transaction_id);

CREATE TABLE IF NOT EXISTS processor_payment_transactions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(50) NOT NULL,
    provider_payment_id VARCHAR(255) NOT NULL,
    provider_charge_id VARCHAR(255) NULL,
    provider_customer_id VARCHAR(255) NULL,
    status VARCHAR(50) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'usd',
    gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    fee_amount DECIMAL(12,2) NULL,
    net_amount DECIMAL(12,2) NULL,
    paid_at DATETIME NULL,
    payer_name VARCHAR(255) NULL,
    payer_email VARCHAR(255) NULL,
    payer_phone VARCHAR(50) NULL,
    payer_address_line1 VARCHAR(255) NULL,
    payer_address_line2 VARCHAR(255) NULL,
    payer_city VARCHAR(100) NULL,
    payer_state VARCHAR(50) NULL,
    payer_postal_code VARCHAR(20) NULL,
    payer_country VARCHAR(100) NULL,
    payment_id INT NULL,
    import_status ENUM('skipped','imported','failed') NOT NULL DEFAULT 'skipped',
    import_error VARCHAR(1000) NULL,
    metadata_json JSON NULL,
    raw_summary_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_processor_payment (provider, provider_payment_id),
    INDEX idx_processor_payment_status (provider, status, paid_at),
    INDEX idx_processor_import_status (import_status, updated_at),
    INDEX idx_processor_payment_link (payment_id),
    CONSTRAINT fk_processor_payment_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS processor_webhook_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(50) NOT NULL,
    provider_event_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    status ENUM('processing','processed','failed') NOT NULL DEFAULT 'processing',
    attempts SMALLINT NOT NULL DEFAULT 1,
    last_error TEXT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_processor_webhook_event (provider, provider_event_id),
    INDEX idx_processor_webhook_status (provider, status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_config (organization_id, config_key, config_value) VALUES
    (0, 'processor_import_standalone_income', '0'),
    (0, 'processor_import_auto_create_clients', '0')
ON DUPLICATE KEY UPDATE config_value = app_config.config_value;
