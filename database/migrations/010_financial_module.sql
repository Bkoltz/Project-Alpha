-- Migration 010: Clean Schema - Financial Module
-- Creates all financial-related tables: payments, receipts, tax rates, etc.
-- Suitable for fresh initialization of the financial module
-- Date: 2026-05-05

USE project_alpha;

-- ============================================================================
-- PAYMENTS
-- ============================================================================
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    invoice_id INT NULL,
    contract_id INT NULL,
    organization_id INT NULL,
    amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    payment_method ENUM('cash', 'check', 'card', 'bank_transfer', 'stripe', 'other') NOT NULL DEFAULT 'cash',
    payment_date DATE NOT NULL,
    reference_number VARCHAR(255) NULL,
    notes TEXT NULL,
    surcharge_paid DECIMAL(12, 2) NOT NULL DEFAULT 0,
    stripe_session_id VARCHAR(255) NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    auto_pay_attempt TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending', 'succeeded', 'failed', 'refunded') NOT NULL DEFAULT 'succeeded',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payments_client (client_id),
    INDEX idx_payments_invoice (invoice_id),
    INDEX idx_payments_contract (contract_id),
    INDEX idx_payments_date (payment_date),
    CONSTRAINT fk_payments_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PAYMENT METHODS
-- ============================================================================
CREATE TABLE IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    organization_id INT NULL,
    name VARCHAR(100) NOT NULL,
    type ENUM('cash', 'check', 'card', 'bank_transfer', 'stripe', 'other') NOT NULL DEFAULT 'cash',
    provider VARCHAR(50) NULL,
    config JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_four VARCHAR(4) NULL,
    exp_month INT NULL,
    exp_year INT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    stripe_payment_method_id VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pm_user (user_id),
    INDEX idx_pm_org (organization_id),
    CONSTRAINT fk_pm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TAX RATES
-- ============================================================================
CREATE TABLE IF NOT EXISTS tax_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    name VARCHAR(100) NOT NULL,
    rate DECIMAL(5, 2) NOT NULL DEFAULT 0,
    county VARCHAR(100) NULL,
    state VARCHAR(2) NULL,
    zip_code VARCHAR(10) NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tax_org (organization_id),
    INDEX idx_tax_county (county),
    INDEX idx_tax_state (state),
    INDEX idx_tax_zip (zip_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ITEM LIBRARY
-- ============================================================================
CREATE TABLE IF NOT EXISTS item_library (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    item_name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    category VARCHAR(100) NULL,
    sku VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_item_lib_org (organization_id),
    INDEX idx_item_lib_item_name (item_name),
    INDEX idx_item_lib_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- RECEIPT STORES
-- ============================================================================
CREATE TABLE IF NOT EXISTS receipt_stores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    address TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_receipt_store_org_name (organization_id, name),
    INDEX idx_store_org (organization_id),
    CONSTRAINT fk_receipt_stores_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- RECEIPTS
-- ============================================================================
CREATE TABLE IF NOT EXISTS receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    store_id INT NULL,
    client_id INT NULL,
    project_id INT NULL,
    amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    receipt_date DATE NOT NULL,
    description TEXT NULL,
    file_path VARCHAR(255) NULL,
    file_name VARCHAR(255) NULL,
    file_size BIGINT UNSIGNED NULL,
    mime_type VARCHAR(150) NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_receipt_org (organization_id),
    INDEX idx_receipt_store (store_id),
    INDEX idx_receipt_client (client_id),
    INDEX idx_receipt_project (project_id),
    INDEX idx_receipt_date (receipt_date),
    CONSTRAINT fk_receipts_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_receipts_store FOREIGN KEY (store_id) REFERENCES receipt_stores(id) ON DELETE SET NULL,
    CONSTRAINT fk_receipts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_receipts_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_receipts_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- FORM CATEGORIES
-- ============================================================================
CREATE TABLE IF NOT EXISTS form_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    type ENUM('file', 'folder') NOT NULL DEFAULT 'folder',
    description TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_form_cat_org (organization_id),
    INDEX idx_form_cat_type (type),
    CONSTRAINT fk_form_cat_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_cat_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- FORM DOCUMENTS
-- ============================================================================
CREATE TABLE IF NOT EXISTS form_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    category_id INT NULL,
    client_id INT NULL,
    project_id INT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_size BIGINT UNSIGNED NULL,
    mime_type VARCHAR(150) NULL,
    description TEXT NULL,
    file_path VARCHAR(255) NULL,
    status ENUM('draft', 'active', 'archived') NOT NULL DEFAULT 'draft',
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_form_doc_org (organization_id),
    INDEX idx_form_doc_category (category_id),
    INDEX idx_form_doc_client (client_id),
    INDEX idx_form_doc_project (project_id),
    CONSTRAINT fk_form_docs_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_docs_category FOREIGN KEY (category_id) REFERENCES form_categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_docs_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_form_docs_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_form_docs_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
