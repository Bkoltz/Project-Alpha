-- ============================================================================
-- DOCUMENTS (Quotes, Contracts, Invoices, Settings)
-- ============================================================================

  IF NOT EXISTS public_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(16) NOT NULL,
    record_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    redirect VARCHAR(255) NULL,
    expires_at DATETIME NOT NULL,
    revoked TINYINT (1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_public_token (token),
    INDEX idx_public_type_record (type, record_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Notifications for automated invoice emails (track per-invoice sends)
CREATE TABLE
  IF NOT EXISTS invoice_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    type VARCHAR(32) NOT NULL, -- e.g. 'due_7', 'overdue_weekly'
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invnot_invoice (invoice_id),
    INDEX idx_invnot_type (type),
    CONSTRAINT fk_invnot_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- PaymentMethod
-- - id
-- - user_id (or org_id)
-- - type (enum: stripe, paypal, venmo)
-- - config (json: API keys, account IDs)
-- - active (boolean)
  IF NOT EXISTS public_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(16) NOT NULL,
    record_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    redirect VARCHAR(255) NULL,
    expires_at DATETIME NOT NULL,
    revoked TINYINT (1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_public_token (token),
    INDEX idx_public_type_record (type, record_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Notifications for automated invoice emails (track per-invoice sends)
CREATE TABLE
  IF NOT EXISTS invoice_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    type VARCHAR(32) NOT NULL, -- e.g. 'due_7', 'overdue_weekly'
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invnot_invoice (invoice_id),
    INDEX idx_invnot_type (type),
    CONSTRAINT fk_invnot_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NULL,
    base_contract_id INT NULL,
    client_id INT NOT NULL,
    project_id INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM (
      'draft',
      'pending',
      'active',
      'paused',
      'cancelled',
      'completed'
    ) NOT NULL DEFAULT 'pending',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    billing_interval_count INT NOT NULL DEFAULT 1,
    billing_interval_unit ENUM ('day', 'week', 'month', 'year') NOT NULL DEFAULT 'month',
    pricing_type ENUM ('fixed_total', 'per_invoice') NOT NULL DEFAULT 'per_invoice',
    price_per_invoice DECIMAL(12, 2) NULL,
    discount_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_paid DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total_invoiced DECIMAL(12, 2) NOT NULL DEFAULT 0,
    next_invoice_date DATE NULL,
    last_invoice_date DATE NULL,
    signed_pdf_path VARCHAR(255) NULL,
    scope TEXT NULL,
    terms TEXT NULL,
    custom_fields JSON NULL,
    auto_pay_enabled TINYINT (1) DEFAULT 0,
    payment_method_id INT NULL,
    stripe_subscription_id VARCHAR(255) NULL,
    invoice_count INT NULL COMMENT 'For fixed_total pricing: number of invoices to divide total',
    invoices_generated INT DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ltc_client (client_id),
    INDEX idx_ltc_status (status),
    INDEX idx_ltc_doc (doc_number),
    INDEX idx_ltc_project (project_code),
    INDEX idx_ltc_project_id (project_id),
    INDEX idx_ltc_next_invoice (next_invoice_date),
    INDEX idx_ltc_auto_pay (auto_pay_enabled),
    INDEX idx_ltc_stripe_sub (stripe_subscription_id),
    CONSTRAINT fk_ltc_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_ltc_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE SET NULL,
    CONSTRAINT fk_ltc_base_contract FOREIGN KEY (base_contract_id) REFERENCES contracts (id) ON DELETE SET NULL
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

  IF NOT EXISTS on_demand_contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NULL,
    client_id INT NOT NULL,
    project_id INT NULL,
    doc_number INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM (
      'draft',
      'pending',
      'active',
      'paused',
      'cancelled',
      'completed'
    ) NOT NULL DEFAULT 'pending',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    billing_interval_count INT NOT NULL DEFAULT 1,
    billing_interval_unit ENUM ('day', 'week', 'month', 'year') NOT NULL DEFAULT 'month',
    discount_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    price_per_invoice DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    deposit_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_paid DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total_invoiced DECIMAL(12, 2) NOT NULL DEFAULT 0,
    invoice_count INT NOT NULL DEFAULT 0,
    last_invoice_date DATE NULL,
    signed_pdf_path VARCHAR(255) NULL,
    scope TEXT NULL,
    terms TEXT NULL,
    custom_fields JSON NULL,
    auto_pay_enabled TINYINT (1) DEFAULT 0,
    payment_method_id INT NULL,
    invoice_type ENUM ('set_amount', 'itemized', 'general_writeup') DEFAULT 'set_amount',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_odc_client (client_id),
    INDEX idx_odc_status (status),
    INDEX idx_odc_doc (doc_number),
    INDEX idx_odc_project (project_code),
    INDEX idx_odc_project_id (project_id),
    INDEX idx_odc_end_date (end_date),
    INDEX idx_odc_auto_pay (auto_pay_enabled),
    INDEX idx_odc_invoice_type (invoice_type),
    CONSTRAINT fk_odc_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_odc_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE SET NULL
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
  IF NOT EXISTS on_demand_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    on_demand_contract_id INT NOT NULL,
    invoice_id INT NOT NULL,
    invoice_number INT NULL,
    amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    status ENUM ('draft', 'sent', 'paid', 'overdue', 'cancelled') NOT NULL DEFAULT 'draft',
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    due_date DATE NULL,
    notes TEXT NULL,
    CONSTRAINT fk_odinv_contract FOREIGN KEY (on_demand_contract_id) REFERENCES on_demand_contracts (id) ON DELETE CASCADE,
    CONSTRAINT fk_odinv_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    INDEX idx_odinv_contract (on_demand_contract_id),
    INDEX idx_odinv_invoice (invoice_id),
    INDEX idx_odinv_status (status),
    INDEX idx_odinv_due_date (due_date)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
  IF NOT EXISTS recurring_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    project_id INT NULL,
    template_invoice_id INT NULL,
    project_code VARCHAR(64) NULL,
    status ENUM ('active', 'paused', 'cancelled') NOT NULL DEFAULT 'active',
    interval_unit ENUM ('day', 'week', 'month', 'year') NOT NULL DEFAULT 'month',
    interval_count INT NOT NULL DEFAULT 1,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    next_run_date DATE NULL,
    last_run_date DATE NULL,
    max_occurrences INT NULL,
    occurrences_generated INT NOT NULL DEFAULT 0,
    proration TINYINT (1) NOT NULL DEFAULT 0,
    anchor_day TINYINT NULL,
    discount_type ENUM ('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5, 2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_recinv_client (client_id),
    INDEX idx_recinv_status (status),
    INDEX idx_recinv_next (next_run_date),
    INDEX idx_recinv_project (project_code),
    INDEX idx_recinv_project_id (project_id),
    CONSTRAINT fk_recinv_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_recinv_template FOREIGN KEY (template_invoice_id) REFERENCES invoices (id) ON DELETE SET NULL
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
  IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    organization_id INT NULL,
    provider ENUM ('stripe', 'paypal', 'venmo') NOT NULL,
    provider_name VARCHAR(100) NULL,
    config JSON NOT NULL,
    is_active TINYINT (1) DEFAULT 1,
    is_default TINYINT (1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payment_user (user_id),
    INDEX idx_payment_org (organization_id),
    INDEX idx_payment_active (is_active)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
  IF NOT EXISTS contract_signatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NULL,
    long_term_contract_id INT NULL,
    on_demand_contract_id INT NULL,
    signer_title VARCHAR(255) NOT NULL,
    signer_name VARCHAR(255) NULL,
    signer_email VARCHAR(255) NULL,
    signature_data TEXT NULL,
    signed_at TIMESTAMP NULL,
    display_order INT NOT NULL DEFAULT 1,
    is_required TINYINT (1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE CASCADE,
    FOREIGN KEY (long_term_contract_id) REFERENCES long_term_contracts (id) ON DELETE CASCADE,
    FOREIGN KEY (on_demand_contract_id) REFERENCES on_demand_contracts (id) ON DELETE CASCADE,
    INDEX idx_sig_contract (contract_id),
    INDEX idx_sig_ltc (long_term_contract_id),
    INDEX idx_sig_odc (on_demand_contract_id),
    INDEX idx_sig_signed (signed_at)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

  IF NOT EXISTS system_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(16) NOT NULL,
    category VARCHAR(64) NOT NULL,
    actor_type VARCHAR(32) NULL,
    actor_id INT NULL,
    ip VARCHAR(45) NULL,
    message TEXT NULL,
    payload JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_category (category),
    INDEX idx_audit_actor (actor_type, actor_id),
    INDEX idx_audit_created (created_at)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS `audit_schedules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `frequency` ENUM ('weekly', 'monthly', 'quarterly', 'annually') NOT NULL,
    `date_range_type` ENUM (
      'last_week',
      'last_month',
      'last_quarter',
      'last_year',
      'current_year',
      'all_time'
    ) NOT NULL DEFAULT 'current_year',
    `email_addresses` TEXT NOT NULL COMMENT 'JSON array of email addresses',
    `options` JSON NULL COMMENT 'Additional options: include_contracts, include_quotes, include_pdfs, include_unpaid_invoices',
    `is_active` TINYINT (1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `next_run_at` DATETIME NULL COMMENT 'Next scheduled execution time',
    `last_run_at` DATETIME NULL COMMENT 'Last successful execution time'
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Index for finding due schedules
CREATE INDEX idx_next_run ON audit_schedules (next_run_at, is_active);

-- Add schedule execution log table
CREATE TABLE
  IF NOT EXISTS `audit_schedule_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `schedule_id` INT NOT NULL,
    `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM ('success', 'failed') NOT NULL,
    `error_message` TEXT NULL,
    `file_path` VARCHAR(500) NULL COMMENT 'Path to generated audit file',
    `email_sent` TINYINT (1) NOT NULL DEFAULT 0,
    FOREIGN KEY (`schedule_id`) REFERENCES `audit_schedules` (`id`) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE INDEX idx_schedule_logs ON audit_schedule_logs (schedule_id, executed_at);

  IF NOT EXISTS receipt_stores (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    store_name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE,
    UNIQUE KEY unique_store_org (org_id, store_name),
    INDEX idx_store_org (org_id),
    INDEX idx_store_name (store_name)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS receipts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    store_name VARCHAR(255) NULL,
    receipt_date DATE NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL,
    INDEX idx_receipt_org (org_id),
    INDEX idx_receipt_date (receipt_date),
    INDEX idx_receipt_store (store_name)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

  IF NOT EXISTS form_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    type ENUM ('file', 'folder') NOT NULL DEFAULT 'folder' COMMENT 'file=single document, folder=multiple documents',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    INDEX idx_form_cat_org (org_id),
    INDEX idx_form_cat_type (type)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS form_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    project_id INT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_size INT UNSIGNED NULL,
    mime_type VARCHAR(100) NULL,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES form_categories (id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL,
    FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
    INDEX idx_form_doc_category (category_id),
    INDEX idx_form_doc_uploaded (uploaded_at),
    INDEX idx_form_doc_project (project_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

  IF NOT EXISTS document_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_type ENUM ('regular', 'long_term', 'on_demand') NOT NULL,
    settings JSON NOT NULL COMMENT 'Customization settings for document type',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_doc_type (document_type)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Insert default settings for each document type
INSERT IGNORE INTO document_settings (document_type, settings)
VALUES
  (
    'regular',
    '{"show_deposit":true,"show_fulfillment_date":true,"show_scope":true}'
  ),
  (
    'long_term',
    '{"show_deposit":true,"show_fulfillment_date":false,"show_scope":true,"show_billing_settings":true}'
  ),
  (
    'on_demand',
    '{"show_deposit":true,"show_fulfillment_date":false,"show_scope":true,"show_billing_settings":false}'
  );
