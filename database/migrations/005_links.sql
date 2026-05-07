-- ============================================================================
-- LINKS
-- ============================================================================

CREATE TABLE
  IF NOT EXISTS project_counters (
    prefix VARCHAR(32) PRIMARY KEY,
    next_seq INT NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE
  IF NOT EXISTS project_meta (
    project_code VARCHAR(64) PRIMARY KEY,
    client_id INT NOT NULL,
    notes TEXT NULL,
    terms TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_project_meta_client (client_id),
    CONSTRAINT fk_project_meta_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
