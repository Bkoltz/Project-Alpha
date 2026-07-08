ALTER TABLE projects
    ADD COLUMN public_project_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN public_project_token VARCHAR(64) NULL,
    ADD COLUMN public_project_require_password TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN public_project_password_hash VARCHAR(255) NULL,
    ADD COLUMN public_project_can_view_documents TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN public_project_can_view_invoices TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN public_project_can_upload TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN public_project_can_request_changes TINYINT(1) NOT NULL DEFAULT 0,
    ADD UNIQUE KEY uq_projects_public_project_token (public_project_token);

CREATE TABLE IF NOT EXISTS project_public_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    message TEXT NULL,
    file_id INT NULL,
    client_label VARCHAR(190) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_project_public_events_project (project_id),
    INDEX idx_project_public_events_type (event_type),
    CONSTRAINT fk_project_public_events_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_public_events_file FOREIGN KEY (file_id) REFERENCES project_files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
