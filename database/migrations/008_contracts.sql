-- ============================================================================
-- CONTRACTS
-- ============================================================================

  IF NOT EXISTS contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NULL,
    client_id INT NOT NULL,
    project_id INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM (
      'pending',
      'active',
      'completed',
      'cancelled',
      'denied',
      'void'
    ) NOT NULL DEFAULT 'pending',
    discount_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_paid DECIMAL(12, 2) NOT NULL DEFAULT 0,
    signed_pdf_path VARCHAR(255) NULL,
    completed_at TIMESTAMP NULL,
    voided_at TIMESTAMP NULL,
    scheduled_date DATE NULL,
    terms TEXT NULL,
    estimated_completion VARCHAR(200) NULL,
    fulfillment_date DATE NULL,
    weather_pending TINYINT (1) NOT NULL DEFAULT 0,
    scope TEXT NULL,
    custom_fields JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_contracts_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE SET NULL,
    CONSTRAINT fk_contracts_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
    INDEX idx_contracts_client (client_id),
    INDEX idx_contracts_status (status),
    INDEX idx_contracts_doc_number (doc_number),
    INDEX idx_contracts_project_code (project_code),
    INDEX idx_contracts_project_id (project_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS contract_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    item VARCHAR(255) NOT NULL,
    description TEXT NULL,
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_contract_items_contract FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE CASCADE,
    INDEX idx_contract_items_contract (contract_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
