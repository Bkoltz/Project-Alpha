SET @project_public_enabled_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'public_project_enabled'
);
SET @sql := IF(@project_public_enabled_exists = 0, 'ALTER TABLE projects ADD COLUMN public_project_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER project_invoice_auto_email', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @project_public_token_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'public_project_token'
);
SET @sql := IF(@project_public_token_exists = 0, 'ALTER TABLE projects ADD COLUMN public_project_token VARCHAR(64) NULL AFTER public_project_enabled', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @project_public_require_password_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'public_project_require_password'
);
SET @sql := IF(@project_public_require_password_exists = 0, 'ALTER TABLE projects ADD COLUMN public_project_require_password TINYINT(1) NOT NULL DEFAULT 0 AFTER public_project_token', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @project_public_password_hash_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'public_project_password_hash'
);
SET @sql := IF(@project_public_password_hash_exists = 0, 'ALTER TABLE projects ADD COLUMN public_project_password_hash VARCHAR(255) NULL AFTER public_project_require_password', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @project_public_can_view_documents_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'public_project_can_view_documents'
);
SET @sql := IF(@project_public_can_view_documents_exists = 0, 'ALTER TABLE projects ADD COLUMN public_project_can_view_documents TINYINT(1) NOT NULL DEFAULT 1 AFTER public_project_password_hash', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @project_public_can_view_invoices_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'public_project_can_view_invoices'
);
SET @sql := IF(@project_public_can_view_invoices_exists = 0, 'ALTER TABLE projects ADD COLUMN public_project_can_view_invoices TINYINT(1) NOT NULL DEFAULT 1 AFTER public_project_can_view_documents', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @project_public_can_upload_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'public_project_can_upload'
);
SET @sql := IF(@project_public_can_upload_exists = 0, 'ALTER TABLE projects ADD COLUMN public_project_can_upload TINYINT(1) NOT NULL DEFAULT 0 AFTER public_project_can_view_invoices', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @project_public_can_request_changes_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'public_project_can_request_changes'
);
SET @sql := IF(@project_public_can_request_changes_exists = 0, 'ALTER TABLE projects ADD COLUMN public_project_can_request_changes TINYINT(1) NOT NULL DEFAULT 0 AFTER public_project_can_upload', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @project_public_token_index_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'projects' AND index_name = 'uq_projects_public_project_token'
);
SET @sql := IF(@project_public_token_index_exists = 0, 'ALTER TABLE projects ADD UNIQUE KEY uq_projects_public_project_token (public_project_token)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

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
