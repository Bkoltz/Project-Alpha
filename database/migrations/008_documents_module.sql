-- Migration 008: Clean Schema - Documents Module
-- Creates all document-related tables for quotes, contracts, invoices, and signatures
-- Suitable for fresh initialization of the documents module
-- Date: 2026-05-05

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
    status ENUM('draft', 'pending', 'approved', 'denied', 'expired') NOT NULL DEFAULT 'draft',
    billing_mode ENUM('fixed','hourly') NOT NULL DEFAULT 'fixed',
    discount_type ENUM('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12, 2) NULL DEFAULT NULL,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_type ENUM('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    scope TEXT NULL,
    terms TEXT NULL,
    fulfillment_date DATE NULL,
    estimated_completion VARCHAR(200) NULL,
    weather_pending TINYINT(1) NOT NULL DEFAULT 0,
    custom_fields JSON NULL,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_quotes_client (client_id),
    INDEX idx_quotes_org (organization_id),
    INDEX idx_quotes_status (status),
    INDEX idx_quotes_doc_number (doc_number),
    INDEX idx_quotes_project_code (project_code),
    INDEX idx_quotes_project_id (project_id),
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
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    billing_unit ENUM('each','hour') NOT NULL DEFAULT 'each',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_quote_items_quote (quote_id),
    CONSTRAINT fk_quote_items_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CONTRACTS (Unified: regular, long_term, on_demand)
-- ============================================================================
CREATE TABLE IF NOT EXISTS contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_type ENUM('regular', 'long_term', 'on_demand') NOT NULL DEFAULT 'regular',
    billing_mode ENUM('fixed','hourly') NOT NULL DEFAULT 'fixed',
    quote_id INT NULL,
    base_contract_id INT NULL,
    client_id INT NOT NULL,
    project_id INT NULL,
    organization_id INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM('draft', 'pending', 'active', 'paused', 'completed', 'cancelled', 'denied', 'void') NOT NULL DEFAULT 'pending',
    discount_type ENUM('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12, 2) NULL DEFAULT NULL,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_type ENUM('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_paid DECIMAL(12, 2) NOT NULL DEFAULT 0,
    start_date DATE NULL,
    end_date DATE NULL,
    billing_interval_count INT NOT NULL DEFAULT 1,
    billing_interval_unit ENUM('day', 'week', 'month', 'year') NOT NULL DEFAULT 'month',
    pricing_type ENUM('per_invoice', 'fixed_total', 'on_demand') NULL,
    price_per_invoice DECIMAL(12, 2) NULL,
    total_invoiced DECIMAL(12, 2) NOT NULL DEFAULT 0,
    next_invoice_date DATE NULL,
    last_invoice_date DATE NULL,
    invoice_count INT NULL,
    invoices_generated INT DEFAULT 0,
    invoice_type ENUM('set_amount', 'itemized', 'general_writeup') DEFAULT 'set_amount',
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
    auto_pay_enabled TINYINT(1) DEFAULT 0,
    payment_method_id INT NULL,
    stripe_subscription_id VARCHAR(255) NULL,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contracts_type (contract_type),
    INDEX idx_contracts_client (client_id),
    INDEX idx_contracts_org (organization_id),
    INDEX idx_contracts_status (status),
    INDEX idx_contracts_doc_number (doc_number),
    INDEX idx_contracts_project_code (project_code),
    INDEX idx_contracts_project_id (project_id),
    INDEX idx_contracts_next_invoice (next_invoice_date),
    INDEX idx_contracts_auto_pay (auto_pay_enabled),
    INDEX idx_contracts_stripe_sub (stripe_subscription_id),
    CONSTRAINT fk_contracts_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
    CONSTRAINT fk_contracts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_contracts_base FOREIGN KEY (base_contract_id) REFERENCES contracts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CONTRACT ITEMS
-- ============================================================================
CREATE TABLE IF NOT EXISTS contract_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    item VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NULL,
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    billing_unit ENUM('each','hour') NOT NULL DEFAULT 'each',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contract_items_contract (contract_id),
    CONSTRAINT fk_contract_items_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CONTRACT SIGNATURES
-- ============================================================================
CREATE TABLE IF NOT EXISTS contract_signatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    contract_type ENUM('regular', 'long_term', 'on_demand') NOT NULL,
    client_signature TEXT NULL,
    admin_signature TEXT NULL,
    client_signed_at TIMESTAMP NULL,
    admin_signed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cs_contract (contract_id, contract_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INVOICES
-- ============================================================================
CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    contract_id INT NULL,
    project_id INT NULL,
    organization_id INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM('draft', 'sent', 'paid', 'overdue', 'cancelled', 'void') NOT NULL DEFAULT 'draft',
    billing_mode ENUM('fixed','hourly') NOT NULL DEFAULT 'fixed',
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    discount_type ENUM('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(12, 2) NOT NULL DEFAULT 0,
    balance_due DECIMAL(12, 2) NOT NULL DEFAULT 0,
    due_date DATE NULL,
    paid_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    terms TEXT NULL,
    notes TEXT NULL,
    scope TEXT NULL,
    custom_fields JSON NULL,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoices_client (client_id),
    INDEX idx_invoices_contract (contract_id),
    INDEX idx_invoices_org (organization_id),
    INDEX idx_invoices_status (status),
    INDEX idx_invoices_doc_number (doc_number),
    INDEX idx_invoices_project_code (project_code),
    INDEX idx_invoices_due_date (due_date),
    INDEX idx_invoices_project_id (project_id),
    CONSTRAINT fk_invoices_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoices_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INVOICE ITEMS
-- ============================================================================
CREATE TABLE IF NOT EXISTS invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    item VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NULL,
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    billing_unit ENUM('each','hour') NOT NULL DEFAULT 'each',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoice_items_invoice (invoice_id),
    CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- RECURRING INVOICES
-- ============================================================================
CREATE TABLE IF NOT EXISTS recurring_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    contract_id INT NULL,
    project_id INT NULL,
    organization_id INT NULL,
    doc_number INT NULL,
    status ENUM('draft', 'sent', 'paid', 'overdue', 'cancelled', 'void') NOT NULL DEFAULT 'draft',
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    discount_type ENUM('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(12, 2) NOT NULL DEFAULT 0,
    balance_due DECIMAL(12, 2) NOT NULL DEFAULT 0,
    due_date DATE NULL,
    paid_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    terms TEXT NULL,
    notes TEXT NULL,
    scope TEXT NULL,
    custom_fields JSON NULL,
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_recurring_invoices_client (client_id),
    INDEX idx_recurring_invoices_contract (contract_id),
    INDEX idx_recurring_invoices_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
