-- Migration 011: Clean Schema - Projects & Clients Module
-- Creates all project, client, and document-related tables
-- Suitable for fresh initialization of the projects/clients module
-- Date: 2026-05-05

USE project_alpha;

-- ============================================================================
-- CLIENTS
-- ============================================================================
CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    address_line1 VARCHAR(255) NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(2) NULL,
    postal_code VARCHAR(20) NULL,
    country VARCHAR(100) NULL DEFAULT 'US',
    organization_id INT NULL,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    archive_payload JSON NULL,
    notes TEXT NULL,
    custom_fields JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_clients_name (name),
    INDEX idx_clients_email (email),
    INDEX idx_clients_org (organization_id),
    INDEX idx_clients_archived (archived),
    INDEX idx_clients_deleted (deleted_at),
    CONSTRAINT fk_clients_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PROJECTS
-- ============================================================================
CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    parent_id INT NULL,
    organization_id INT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status ENUM('active', 'completed', 'on_hold', 'cancelled') NOT NULL DEFAULT 'active',
    start_date DATE NULL,
    end_date DATE NULL,
    budget DECIMAL(12, 2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_projects_client (client_id),
    INDEX idx_projects_org (organization_id),
    INDEX idx_projects_status (status),
    INDEX idx_projects_parent (parent_id),
    CONSTRAINT fk_projects_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_projects_parent FOREIGN KEY (parent_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_projects_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PROJECT DOCUMENTS
-- ============================================================================
CREATE TABLE IF NOT EXISTS project_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    document_type ENUM('quote', 'contract', 'invoice', 'receipt', 'form', 'other') NOT NULL DEFAULT 'other',
    document_id INT NOT NULL,
    file_path VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_project_docs_project (project_id),
    INDEX idx_project_docs_type (document_type),
    CONSTRAINT fk_project_docs_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PROJECT META
-- ============================================================================
CREATE TABLE IF NOT EXISTS project_meta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    meta_key VARCHAR(100) NOT NULL,
    meta_value TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_meta (project_id, meta_key),
    CONSTRAINT fk_project_meta_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PROJECT COUNTERS
-- ============================================================================
CREATE TABLE IF NOT EXISTS project_counters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    project_id INT NULL,
    counter_type VARCHAR(50) NOT NULL,
    counter_value INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_counter (organization_id, project_id, counter_type),
    INDEX idx_counters_org (organization_id),
    INDEX idx_counters_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- LINK RESOLVER CONFIG
-- ============================================================================
CREATE TABLE IF NOT EXISTS link_resolver_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(100) NOT NULL,
    config_key VARCHAR(100) NOT NULL,
    config_value TEXT NULL,
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_link_resolver (provider, config_key),
    INDEX idx_link_resolver_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DOCUMENT SETTINGS
-- ============================================================================
CREATE TABLE IF NOT EXISTS document_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    document_type VARCHAR(50) NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_doc_settings (organization_id, document_type, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DOCUMENT CUSTOM FIELDS
-- ============================================================================
CREATE TABLE IF NOT EXISTS document_custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    document_type VARCHAR(50) NOT NULL,
    field_name VARCHAR(100) NOT NULL,
    field_type ENUM('text', 'number', 'date', 'boolean', 'select', 'textarea') NOT NULL DEFAULT 'text',
    field_options JSON NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_doc_cf_org (organization_id),
    INDEX idx_doc_cf_type (document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INVOICE NOTIFICATIONS
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
    CONSTRAINT fk_inv_notif_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
