-- ============================================================================
-- QUOTES
-- ============================================================================

  IF NOT EXISTS quotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    project_id INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM ('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    discount_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    fulfillment_date DATE NULL,
    is_long_term TINYINT (1) NOT NULL DEFAULT 0,
    is_on_demand TINYINT (1) NOT NULL DEFAULT 0,
    start_date DATE NULL,
    end_date DATE NULL,
    billing_interval_count INT NULL DEFAULT 1,
    billing_interval_unit ENUM ('day', 'week', 'month', 'year') NULL DEFAULT 'month',
    pricing_type ENUM ('per_invoice', 'fixed_total', 'on_demand') NULL,
    price_per_invoice DECIMAL(12, 2) NULL,
    scope TEXT NULL,
    custom_fields JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_quotes_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
    INDEX idx_quotes_client (client_id),
    INDEX idx_quotes_status (status),
    INDEX idx_quotes_doc_number (doc_number),
    INDEX idx_quotes_project_code (project_code),
    INDEX idx_quotes_project_id (project_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS quote_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NOT NULL,
    item VARCHAR(255) NOT NULL,
    description TEXT NULL,
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_quote_items_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE CASCADE,
    INDEX idx_quote_items_quote (quote_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
