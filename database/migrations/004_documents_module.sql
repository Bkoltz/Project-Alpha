-- ============================================================================
-- Module 004: Quotes, Contracts & Invoices (Separate Tables)
-- ============================================================================
-- Separate tables for each document type with their own enums
-- ============================================================================

USE project_alpha;

-- ============================================================================
-- QUOTES
-- ============================================================================
CREATE TABLE IF NOT EXISTS quotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    project_id INT NULL,
    organization_id INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM('draft','pending','approved','denied','rejected','expired') NOT NULL DEFAULT 'draft',
    quote_type ENUM('regular','long_term','on_demand') NOT NULL DEFAULT 'regular',
    discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NULL DEFAULT NULL,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    deposit_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    start_date DATE NULL,
    end_date DATE NULL,
    billing_interval_count INT NOT NULL DEFAULT 1,
    billing_interval_unit ENUM('day','week','month','year') NOT NULL DEFAULT 'month',
    pricing_type ENUM('per_invoice','fixed_total','on_demand') NULL,
    price_per_invoice DECIMAL(12,2) NULL,
    invoice_count INT NULL,
    scope TEXT NULL,
    terms TEXT NULL,
    fulfillment_date DATE NULL,
    weather_pending TINYINT(1) NOT NULL DEFAULT 0,
    estimated_completion VARCHAR(200) NULL,
    custom_fields JSON NULL,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_quotes_client (client_id),
    INDEX idx_quotes_project (project_id),
    INDEX idx_quotes_org (organization_id),
    INDEX idx_quotes_status (status),
    INDEX idx_quotes_type (quote_type),
    INDEX idx_quotes_doc_number (doc_number),
    INDEX idx_quotes_project_code (project_code),
    CONSTRAINT fk_quotes_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_quotes_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_quotes_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- QUOTE ITEMS
-- ============================================================================
CREATE TABLE IF NOT EXISTS quote_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NOT NULL,
    item VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_quote_items_quote (quote_id),
    INDEX idx_quote_items_sort (sort_order),
    CONSTRAINT fk_quote_items_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CONTRACTS
-- ============================================================================
CREATE TABLE IF NOT EXISTS contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NULL,
    client_id INT NOT NULL,
    project_id INT NULL,
    organization_id INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM('draft','pending','active','paused','completed','cancelled','denied','void') NOT NULL DEFAULT 'pending',
    contract_type ENUM('regular','long_term','on_demand') NOT NULL DEFAULT 'regular',
    discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NULL DEFAULT NULL,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    deposit_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    deposit_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    start_date DATE NULL,
    end_date DATE NULL,
    billing_interval_count INT NOT NULL DEFAULT 1,
    billing_interval_unit ENUM('day','week','month','year') NOT NULL DEFAULT 'month',
    pricing_type ENUM('per_invoice','fixed_total','on_demand') NULL,
    price_per_invoice DECIMAL(12,2) NULL,
    total_invoiced DECIMAL(12,2) NOT NULL DEFAULT 0,
    next_invoice_date DATE NULL,
    last_invoice_date DATE NULL,
    invoice_count INT NULL,
    invoices_generated INT NOT NULL DEFAULT 0,
    invoice_generation_type ENUM('set_amount','itemized','general_writeup') NOT NULL DEFAULT 'set_amount',
    signed_pdf_path VARCHAR(255) NULL,
    signed_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    voided_at TIMESTAMP NULL,
    scheduled_date DATE NULL,
    scope TEXT NULL,
    terms TEXT NULL,
    fulfillment_date DATE NULL,
    weather_pending TINYINT(1) NOT NULL DEFAULT 0,
    estimated_completion VARCHAR(200) NULL,
    custom_fields JSON NULL,
    auto_pay_enabled TINYINT(1) NOT NULL DEFAULT 0,
    payment_method_id INT NULL,
    stripe_subscription_id VARCHAR(255) NULL,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contracts_client (client_id),
    INDEX idx_contracts_project (project_id),
    INDEX idx_contracts_org (organization_id),
    INDEX idx_contracts_status (status),
    INDEX idx_contracts_type (contract_type),
    INDEX idx_contracts_doc_number (doc_number),
    INDEX idx_contracts_project_code (project_code),
    INDEX idx_contracts_quote (quote_id),
    INDEX idx_contracts_next_invoice (next_invoice_date),
    INDEX idx_contracts_auto_pay (auto_pay_enabled),
    INDEX idx_contracts_stripe_sub (stripe_subscription_id),
    CONSTRAINT fk_contracts_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
    CONSTRAINT fk_contracts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_contracts_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_contracts_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CONTRACT ITEMS
-- ============================================================================
CREATE TABLE IF NOT EXISTS contract_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    item VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contract_items_contract (contract_id),
    INDEX idx_contract_items_sort (sort_order),
    CONSTRAINT fk_contract_items_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CONTRACT SIGNATURES
-- ============================================================================
CREATE TABLE IF NOT EXISTS contract_signatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    signatory_type ENUM('client','admin','witness') NOT NULL DEFAULT 'client',
    signature_data TEXT NULL,
    signed_at TIMESTAMP NULL,
    signed_by_user_id INT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cs_contract (contract_id),
    INDEX idx_cs_type (signatory_type),
    CONSTRAINT fk_cs_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_cs_user FOREIGN KEY (signed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CONTRACT NOTES
-- ============================================================================
CREATE TABLE IF NOT EXISTS contract_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    note TEXT NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cn_contract (contract_id),
    CONSTRAINT fk_cn_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_cn_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INVOICES
-- ============================================================================
CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NULL,
    quote_id INT NULL,
    client_id INT NOT NULL,
    project_id INT NULL,
    organization_id INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM('draft','sent','unpaid','partial','paid','overdue','cancelled','void') NOT NULL DEFAULT 'draft',
    invoice_type ENUM('regular','long_term','on_demand') NOT NULL DEFAULT 'regular',
    is_deposit_invoice TINYINT(1) NOT NULL DEFAULT 0,
    parent_contract_type ENUM('contract','long_term_contract','on_demand_contract') NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance_due DECIMAL(12,2) NOT NULL DEFAULT 0,
    due_date DATE NULL,
    fulfillment_date DATE NULL,
    paid_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    terms TEXT NULL,
    notes TEXT NULL,
    scope TEXT NULL,
    custom_fields JSON NULL,
    stripe_session_id VARCHAR(255) NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    last_auto_pay_attempt TIMESTAMP NULL,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    generated_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoices_client (client_id),
    INDEX idx_invoices_contract (contract_id),
    INDEX idx_invoices_quote (quote_id),
    INDEX idx_invoices_project (project_id),
    INDEX idx_invoices_org (organization_id),
    INDEX idx_invoices_status (status),
    INDEX idx_invoices_type (invoice_type),
    INDEX idx_invoices_doc_number (doc_number),
    INDEX idx_invoices_project_code (project_code),
    INDEX idx_invoices_due_date (due_date),
    INDEX idx_invoices_auto_pay_attempt (last_auto_pay_attempt),
    CONSTRAINT fk_invoices_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoices_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INVOICE ITEMS
-- ============================================================================
CREATE TABLE IF NOT EXISTS invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    item VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    is_extra_charge TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoice_items_invoice (invoice_id),
    INDEX idx_invoice_items_sort (sort_order),
    CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INVOICE NOTIFICATIONS
-- ============================================================================
CREATE TABLE IF NOT EXISTS invoice_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    type ENUM('reminder','overdue','paid','sent') NOT NULL DEFAULT 'reminder',
    notification_type ENUM('reminder','overdue','paid','sent') NOT NULL DEFAULT 'reminder',
    sent_at TIMESTAMP NULL,
    email_to VARCHAR(255) NULL,
    email_subject VARCHAR(255) NULL,
    email_body TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inv_notif_invoice (invoice_id),
    INDEX idx_inv_notif_type (notification_type),
    CONSTRAINT fk_inv_notif_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- RECURRING INVOICES
-- ============================================================================
CREATE TABLE IF NOT EXISTS recurring_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    client_id INT NOT NULL,
    project_id INT NULL,
    organization_id INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM('draft','sent','paid','overdue','cancelled','void') NOT NULL DEFAULT 'draft',
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance_due DECIMAL(12,2) NOT NULL DEFAULT 0,
    due_date DATE NULL,
    paid_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    terms TEXT NULL,
    notes TEXT NULL,
    scope TEXT NULL,
    custom_fields JSON NULL,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    generated_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_recurring_contract (contract_id),
    INDEX idx_recurring_client (client_id),
    INDEX idx_recurring_project (project_id),
    INDEX idx_recurring_org (organization_id),
    INDEX idx_recurring_status (status),
    INDEX idx_recurring_doc_number (doc_number),
    INDEX idx_recurring_due_date (due_date),
    CONSTRAINT fk_recurring_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_recurring_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_recurring_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_recurring_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- RECURRING INVOICE ITEMS
-- ============================================================================
CREATE TABLE IF NOT EXISTS recurring_invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recurring_invoice_id INT NOT NULL,
    item VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ri_items_invoice (recurring_invoice_id),
    INDEX idx_ri_items_sort (sort_order),
    CONSTRAINT fk_ri_items_invoice FOREIGN KEY (recurring_invoice_id) REFERENCES recurring_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- QUOTE HISTORY
-- ============================================================================
CREATE TABLE IF NOT EXISTS quote_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    details JSON NULL,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_qh_quote (quote_id),
    INDEX idx_qh_created (created_at),
    CONSTRAINT fk_qh_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
    CONSTRAINT fk_qh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CONTRACT HISTORY
-- ============================================================================
CREATE TABLE IF NOT EXISTS contract_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    details JSON NULL,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ch_contract (contract_id),
    INDEX idx_ch_created (created_at),
    CONSTRAINT fk_ch_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_ch_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INVOICE HISTORY
-- ============================================================================
CREATE TABLE IF NOT EXISTS invoice_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    details JSON NULL,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ih_invoice (invoice_id),
    INDEX idx_ih_created (created_at),
    CONSTRAINT fk_ih_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_ih_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
