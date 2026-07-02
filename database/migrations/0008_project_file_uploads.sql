CREATE TABLE IF NOT EXISTS project_file_folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    client_visible TINYINT(1) NOT NULL DEFAULT 0,
    client_upload_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_file_folder_name (project_id, name),
    INDEX idx_project_file_folders_project (project_id),
    INDEX idx_project_file_folders_created_by (created_by),
    CONSTRAINT fk_project_file_folders_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_file_folders_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    folder_id INT NULL,
    original_name VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(190) NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    client_visible TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_project_files_project (project_id),
    INDEX idx_project_files_folder (folder_id),
    INDEX idx_project_files_uploaded_by (uploaded_by),
    CONSTRAINT fk_project_files_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_files_folder FOREIGN KEY (folder_id) REFERENCES project_file_folders(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_files_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
