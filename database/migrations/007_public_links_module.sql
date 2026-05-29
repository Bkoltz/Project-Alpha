-- ============================================================================
-- Module 007: Public Links, Document Customization & Integrations
-- ============================================================================
-- Shareable links, document custom fields, document settings, link resolver
-- ============================================================================

USE project_alpha;

-- ============================================================================
-- PUBLIC LINKS (Shareable document/record links)
-- ============================================================================
CREATE TABLE IF NOT EXISTS public_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    document_type VARCHAR(50) NOT NULL,
    document_id INT NOT NULL,
    expires_at DATETIME NULL,
    expire_when_paid TINYINT(1) NOT NULL DEFAULT 0,
    revoked TINYINT(1) NOT NULL DEFAULT 0,
    redirect VARCHAR(500) NULL,
    access_count INT NOT NULL DEFAULT 0,
    last_accessed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_public_links_token (token),
    INDEX idx_public_links_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- LINK RESOLVER CONFIG (External storage integrations)
-- ============================================================================
CREATE TABLE IF NOT EXISTS link_resolver_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(100) NOT NULL,
    config_key VARCHAR(100) NULL,
    config_value TEXT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    credentials JSON NULL,
    default_expiration_days INT NULL,
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_link_resolver (provider, config_key),
    INDEX idx_link_resolver_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DOCUMENT CUSTOM FIELDS (Dynamic fields per document type)
-- ============================================================================
CREATE TABLE IF NOT EXISTS document_custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    document_type VARCHAR(50) NOT NULL DEFAULT 'quote',
    field_name VARCHAR(100) NULL,
    field_key VARCHAR(100) NULL,
    field_label VARCHAR(100) NULL,
    field_data_type VARCHAR(50) NULL,
    field_type ENUM('text', 'number', 'date', 'boolean', 'select', 'textarea') NOT NULL DEFAULT 'text',
    field_options JSON NULL,
    default_value TEXT NULL,
    min_value DECIMAL(12,2) NULL,
    max_value DECIMAL(12,2) NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_builtin TINYINT(1) NOT NULL DEFAULT 0,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_doc_cf_org (organization_id),
    INDEX idx_doc_cf_type (document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DOCUMENT SETTINGS (Per-org/document type configuration)
-- ============================================================================
CREATE TABLE IF NOT EXISTS document_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL DEFAULT 0,
    document_type VARCHAR(50) NOT NULL,
    settings JSON NULL,
    setting_key VARCHAR(100) NULL,
    setting_value TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_doc_settings (organization_id, document_type, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CUSTOM FIELDS VALUES (Actual values stored per document)
-- ============================================================================
CREATE TABLE IF NOT EXISTS document_custom_field_values (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    custom_field_id INT NOT NULL,
    field_value TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_doc_cf_value (document_id, custom_field_id),
    INDEX idx_doc_cfv_document (document_id),
    INDEX idx_doc_cfv_field (custom_field_id),
    CONSTRAINT fk_doc_cfv_document FOREIGN KEY (document_id) REFERENCES quotes(id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_cfv_field FOREIGN KEY (custom_field_id) REFERENCES document_custom_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ARCHIVED ENTITIES (Generic soft delete for any entity)
-- ============================================================================
CREATE TABLE IF NOT EXISTS archived_entities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NULL,
    organization_id INT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NOT NULL,
    payload JSON NOT NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_arch_entities_client (client_id),
    INDEX idx_arch_entities_org (organization_id),
    INDEX idx_arch_entities_type (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ARCHIVED CLIENTS
-- ============================================================================
CREATE TABLE IF NOT EXISTS archived_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    organization_id INT NULL,
    notes TEXT NULL,
    address_line1 VARCHAR(255) NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(2) NULL,
    postal VARCHAR(20) NULL,
    country VARCHAR(100) NULL DEFAULT 'US',
    created_at TIMESTAMP NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_archived_clients_client (client_id),
    INDEX idx_archived_clients_org (organization_id),
    INDEX idx_archived_clients_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
