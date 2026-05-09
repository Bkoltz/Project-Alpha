-- ============================================================================
-- Module 004: Documents Module (Consolidated)
-- ============================================================================
-- Unified documents table replaces: quotes, contracts, invoices, recurring_invoices
-- Document types: quote, contract, invoice, recurring_invoice
-- Includes: items, signatures, notes, history, notifications
-- ============================================================================

USE project_alpha;

-- ============================================================================
-- DOCUMENTS (Unified: quotes, contracts, invoices, recurring_invoices)
-- ============================================================================
CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_type ENUM('quote', 'contract', 'invoice', 'recurring_invoice') NOT NULL,
    
    -- Core references
    client_id INT NOT NULL,
    project_id INT NULL,
    organization_id INT NULL,
    parent_document_id INT NULL,           -- For recurring invoices linked to contract
    quote_id INT NULL,                     -- Contract linked to quote
    
    -- Document numbering
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    on_demand_invoice_number INT NULL,     -- Only for on-demand invoices
    
    -- Status (varies by document type, enforced at application level)
    status VARCHAR(50) NOT NULL DEFAULT 'draft',
    -- quote:    draft, pending, approved, denied, expired
    -- contract: draft, pending, active, paused, completed, cancelled, denied, void
    -- invoice:  draft, sent, unpaid, partial, paid, overdue, cancelled, void
    -- recurring: draft, sent, paid, overdue, cancelled, void
    
    -- Financials
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    discount_type ENUM('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12, 2) NULL DEFAULT NULL,
    tax_county VARCHAR(100) NULL DEFAULT NULL,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    
    -- Deposit (quotes/contracts)
    deposit_type ENUM('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_paid DECIMAL(12, 2) NOT NULL DEFAULT 0,       -- Contracts only
    
    -- Payment tracking (invoices)
    amount_paid DECIMAL(12, 2) NOT NULL DEFAULT 0,
    balance_due DECIMAL(12, 2) NOT NULL DEFAULT 0,
    due_date DATE NULL,
    paid_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    
    -- Contract-specific fields
    contract_type ENUM('regular', 'long_term', 'on_demand') NULL,  -- Only for contracts
    start_date DATE NULL,
    end_date DATE NULL,
    billing_interval_count INT NOT NULL DEFAULT 1,
    billing_interval_unit ENUM('day', 'week', 'month', 'year') NOT NULL DEFAULT 'month',
    pricing_type ENUM('per_invoice', 'fixed_total', 'on_demand') NULL,
    price_per_invoice DECIMAL(12, 2) NULL,
    total_invoiced DECIMAL(12, 2) NOT NULL DEFAULT 0,      -- Contracts
    next_invoice_date DATE NULL,                           -- Contracts
    last_invoice_date DATE NULL,                            -- Contracts
    invoice_count INT NULL,                                 -- Contracts
    invoices_generated INT NOT NULL DEFAULT 0,             -- Contracts
    invoice_generation_type ENUM('set_amount', 'itemized', 'general_writeup') NOT NULL DEFAULT 'set_amount',
    
    -- Signature / Completion tracking
    signed_pdf_path VARCHAR(255) NULL,
    signed_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    voided_at TIMESTAMP NULL,
    scheduled_date DATE NULL,
    
    -- Content
    scope TEXT NULL,
    terms TEXT NULL,
    fulfillment_date DATE NULL,
    weather_pending TINYINT(1) NOT NULL DEFAULT 0,
    estimated_completion VARCHAR(200) NULL,
    notes TEXT NULL,
    on_demand_notes TEXT NULL,                             -- On-demand invoices
    
    -- Custom fields
    custom_fields JSON NULL,
    
    -- Stripe integration
    auto_pay_enabled TINYINT(1) NOT NULL DEFAULT 0,
    payment_method_id INT NULL,
    stripe_subscription_id VARCHAR(255) NULL,
    stripe_session_id VARCHAR(255) NULL,                   -- Invoice payments
    stripe_payment_intent_id VARCHAR(255) NULL,              -- Invoice payments
    
    -- Document dates
    document_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    document_date_updated_at TIMESTAMP NULL DEFAULT NULL,
    generated_at TIMESTAMP NULL,                             -- When auto-generated
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_documents_type (document_type),
    INDEX idx_documents_client (client_id),
    INDEX idx_documents_project (project_id),
    INDEX idx_documents_org (organization_id),
    INDEX idx_documents_status (status),
    INDEX idx_documents_doc_number (doc_number),
    INDEX idx_documents_project_code (project_code),
    INDEX idx_documents_parent (parent_document_id),
    INDEX idx_documents_quote (quote_id),
    INDEX idx_documents_due_date (due_date),
    INDEX idx_documents_next_invoice (next_invoice_date),
    INDEX idx_documents_contract_type (contract_type),
    INDEX idx_documents_auto_pay (auto_pay_enabled),
    INDEX idx_documents_stripe_sub (stripe_subscription_id),
    
    -- Foreign keys
    CONSTRAINT fk_documents_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_documents_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_documents_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_documents_parent FOREIGN KEY (parent_document_id) REFERENCES documents(id) ON DELETE SET NULL,
    CONSTRAINT fk_documents_quote FOREIGN KEY (quote_id) REFERENCES documents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DOCUMENT ITEMS (Unified line items for all document types)
-- ============================================================================
CREATE TABLE IF NOT EXISTS document_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    item VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NULL,
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    is_extra_charge TINYINT(1) NOT NULL DEFAULT 0,         -- Invoice extra charges
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_document_items_document (document_id),
    INDEX idx_document_items_sort (sort_order),
    CONSTRAINT fk_document_items_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DOCUMENT SIGNATURES (Supports multiple signatories)
-- ============================================================================
CREATE TABLE IF NOT EXISTS document_signatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    signatory_type ENUM('client', 'admin', 'witness') NOT NULL DEFAULT 'client',
    signature_data TEXT NULL,
    signed_at TIMESTAMP NULL,
    signed_by_user_id INT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_doc_sigs_document (document_id),
    INDEX idx_doc_sigs_type (signatory_type),
    CONSTRAINT fk_doc_sigs_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_sigs_user FOREIGN KEY (signed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DOCUMENT NOTES (Threaded notes/comments on documents)
-- ============================================================================
CREATE TABLE IF NOT EXISTS document_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    note TEXT NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_document_notes_document (document_id),
    CONSTRAINT fk_document_notes_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_document_notes_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DOCUMENT HISTORY (Status changes, edits, etc.)
-- ============================================================================
CREATE TABLE IF NOT EXISTS document_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    details JSON NULL,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_doc_hist_document (document_id),
    INDEX idx_doc_hist_created (created_at),
    CONSTRAINT fk_doc_hist_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_hist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INVOICE NOTIFICATIONS (Reminders, overdue notices, payment confirmations)
-- ============================================================================
CREATE TABLE IF NOT EXISTS invoice_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    notification_type ENUM('reminder', 'overdue', 'paid', 'sent') NOT NULL DEFAULT 'reminder',
    sent_at TIMESTAMP NULL,
    email_to VARCHAR(255) NULL,
    email_subject VARCHAR(255) NULL,
    email_body TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inv_notif_invoice (invoice_id),
    INDEX idx_inv_notif_type (notification_type),
    CONSTRAINT fk_inv_notif_invoice FOREIGN KEY (invoice_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
