-- ============================================================================
-- PROJECTS
-- ============================================================================

  IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    client_id INT NULL,
    parent_id INT NULL,
    organization_id INT NULL,
    status ENUM (
      'not_started',
      'active',
      'overdue',
      'completed',
      'cancelled'
    ) NOT NULL DEFAULT 'not_started',
    estimated_start DATE NULL,
    estimated_end DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_projects_client (client_id),
    INDEX idx_projects_parent (parent_id),
    INDEX idx_projects_organization (organization_id),
    INDEX idx_projects_status (status)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS project_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    document_type ENUM (
      'quote',
      'contract',
      'invoice',
      'recurring_invoice',
      'long_term_contract',
      'on_demand_contract'
    ) NOT NULL,
    document_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_project_documents_project (project_id),
    INDEX idx_project_documents_type (document_type, document_id)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
