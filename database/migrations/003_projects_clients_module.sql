-- ============================================================================
-- Module 003: Projects & Clients
-- ============================================================================
-- Clients, projects, project metadata, counters, and project document links
-- ============================================================================

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
    config JSON NULL,
    stripe_customer_id VARCHAR(255) NULL,
    stripe_payment_method_id VARCHAR(255) NULL,
    auto_pay_enabled TINYINT(1) NOT NULL DEFAULT 0,
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
    INDEX idx_clients_stripe_customer (stripe_customer_id),
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
    status ENUM('not_started', 'active', 'overdue', 'completed', 'cancelled') NOT NULL DEFAULT 'not_started',
    start_date DATE NULL,
    end_date DATE NULL,
    estimated_start DATE NULL,
    estimated_end DATE NULL,
    budget DECIMAL(12, 2) NULL,
    notes TEXT NULL,
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
-- PROJECT METADATA
-- ============================================================================
CREATE TABLE IF NOT EXISTS project_meta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NULL,
    project_code VARCHAR(64) NULL,
    client_id INT NULL,
    meta_key VARCHAR(100) NULL,
    meta_value TEXT NULL,
    notes TEXT NULL,
    terms TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_meta_key (project_id, meta_key),
    UNIQUE KEY uq_project_meta_code (project_code),
    INDEX idx_project_meta_client (client_id),
    CONSTRAINT fk_project_meta_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_meta_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PROJECT COUNTERS (Document numbering per org/project)
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
-- PROJECT DOCUMENTS (Links documents to projects)
-- ============================================================================
CREATE TABLE IF NOT EXISTS project_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    document_type ENUM('quote', 'contract', 'invoice', 'recurring_invoice', 'receipt', 'form', 'other') NOT NULL DEFAULT 'other',
    document_id INT NOT NULL,
    file_path VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_project_docs_project (project_id),
    INDEX idx_project_docs_type (document_type, document_id),
    CONSTRAINT fk_project_docs_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ENTITY LINKS (External resources for clients/organizations)
-- ============================================================================
CREATE TABLE IF NOT EXISTS entity_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('client', 'organization', 'project') NOT NULL,
    entity_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    url VARCHAR(500) NOT NULL,
    link_type ENUM('manual', 'auto_dropbox', 'auto_gdrive', 'auto_s3') NOT NULL DEFAULT 'manual',
    expiration_date DATE NULL,
    is_expired TINYINT(1) NOT NULL DEFAULT 0,
    ignore_auto_generation TINYINT(1) NOT NULL DEFAULT 0,
    last_verified TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_link_entity (entity_type, entity_id),
    INDEX idx_link_expired (is_expired),
    INDEX idx_link_expiration (expiration_date),
    INDEX idx_link_ignore (ignore_auto_generation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- LINK RESOLVER LINKS
-- ============================================================================
CREATE TABLE IF NOT EXISTS link (
    link_id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('client', 'organization', 'project') NOT NULL,
    entity_id INT NOT NULL,
    title VARCHAR(255) NULL,
    url VARCHAR(500) NOT NULL,
    type ENUM('manual', 'auto_dropbox', 'auto_gdrive', 'auto_s3') NOT NULL DEFAULT 'manual',
    expiration_date DATE NULL,
    is_expired TINYINT(1) NOT NULL DEFAULT 0,
    ignore_auto_generation TINYINT(1) NOT NULL DEFAULT 0,
    last_verified TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_link_entity (entity_type, entity_id),
    INDEX idx_link_type (type),
    INDEX idx_link_expired (is_expired),
    INDEX idx_link_expiration (expiration_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
