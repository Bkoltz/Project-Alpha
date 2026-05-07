-- ============================================================================
-- INVOICES
-- ============================================================================

  IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NULL,
    quote_id INT NULL,
    long_term_contract_id INT NULL,
    on_demand_contract_id INT NULL,
    client_id INT NOT NULL,
    project_id INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    discount_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12, 2) NULL DEFAULT NULL,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(12, 2) DEFAULT 0,
    status ENUM ('unpaid', 'partial', 'paid', 'void') NOT NULL DEFAULT 'unpaid',
    due_date DATE NULL,
    scheduled_date DATE NULL,
    estimated_completion VARCHAR(200) NULL,
    fulfillment_date DATE NULL,
    weather_pending TINYINT (1) NOT NULL DEFAULT 0,
    scope TEXT NULL,
    custom_fields JSON NULL,
    is_deposit_invoice TINYINT (1) DEFAULT 0,
    parent_contract_type ENUM (
      'contract',
      'long_term_contract',
      'on_demand_contract'
    ) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_invoice_deposit (is_deposit_invoice),
    CONSTRAINT fk_invoices_contract FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
    INDEX idx_invoices_client (client_id),
    INDEX idx_invoices_status (status),
    INDEX idx_invoices_total (total),
    INDEX idx_invoices_doc_number (doc_number),
    INDEX idx_invoices_project_code (project_code),
    INDEX idx_invoices_project_id (project_id),
    INDEX idx_invoices_ltc (long_term_contract_id),
    INDEX idx_invoices_odc (on_demand_contract_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    item VARCHAR(255) NOT NULL,
    description TEXT NULL,
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    is_extra_charge TINYINT (1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    INDEX idx_invoice_items_invoice (invoice_id),
    INDEX idx_invoice_items_extra (is_extra_charge)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
