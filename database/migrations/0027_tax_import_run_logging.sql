CREATE TABLE IF NOT EXISTS tax_import_runs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    state_fips VARCHAR(2) NOT NULL,
    state_abbr VARCHAR(2) NOT NULL,
    status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    phase VARCHAR(80) NOT NULL DEFAULT 'starting',
    message VARCHAR(255) NULL,
    fips_rows INT NOT NULL DEFAULT 0,
    rate_rows INT NOT NULL DEFAULT 0,
    boundary_rows INT NOT NULL DEFAULT 0,
    warning_count INT NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    error_message TEXT NULL,
    INDEX idx_tax_import_runs_state (state_fips, started_at),
    INDEX idx_tax_import_runs_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
