-- Immutable worker agreements and explicit mileage ownership.
-- This migration was generalized before deployment; there is no released
-- employee_documents table to preserve.

CREATE TABLE IF NOT EXISTS worker_documents (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    worker_name_snapshot VARCHAR(255) NOT NULL,
    worker_email_snapshot VARCHAR(255) NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'other',
    title VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    signed_on DATE NULL,
    expires_on DATE NULL,
    status ENUM('current','archived') NOT NULL DEFAULT 'current',
    worker_visible TINYINT(1) NOT NULL DEFAULT 0,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    version_number INT UNSIGNED NOT NULL DEFAULT 1,
    uploaded_by INT NULL,
    archived_by INT NULL,
    archived_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_worker_document_path (file_path),
    INDEX idx_worker_documents_user_status (user_id,status,created_at),
    INDEX idx_worker_documents_expiry (expires_on,status),
    INDEX idx_worker_documents_hash (content_sha256),
    CONSTRAINT fk_worker_document_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_worker_document_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_worker_document_archiver FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mileage_logs.user_id remains as a compatibility alias for the traveler.
-- New code writes both explicit columns so reports never confuse the person
-- entering a record with the person whose travel it represents.
SET @has_mileage_recorded_by := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='mileage_logs' AND column_name='recorded_by_user_id'
);
SET @sql := IF(
    @has_mileage_recorded_by=0,
    'ALTER TABLE mileage_logs ADD COLUMN recorded_by_user_id INT NULL AFTER user_id, ADD INDEX idx_mileage_recorded_by (recorded_by_user_id), ADD CONSTRAINT fk_mileage_recorded_by FOREIGN KEY (recorded_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_mileage_traveler := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='mileage_logs' AND column_name='traveler_user_id'
);
SET @sql := IF(
    @has_mileage_traveler=0,
    'ALTER TABLE mileage_logs ADD COLUMN traveler_user_id INT NULL AFTER recorded_by_user_id, ADD INDEX idx_mileage_traveler (traveler_user_id), ADD CONSTRAINT fk_mileage_traveler FOREIGN KEY (traveler_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE mileage_logs
SET recorded_by_user_id=COALESCE(recorded_by_user_id,user_id),
    traveler_user_id=COALESCE(traveler_user_id,user_id)
WHERE recorded_by_user_id IS NULL OR traveler_user_id IS NULL;
