-- Migration 0064: Provider-neutral, default-off Sync Contract v2 foundation.
-- Project Alpha remains authoritative; these tables hold only integration
-- identity, resource-version, event-sequence, and snapshot-session metadata.

CREATE TABLE IF NOT EXISTS sync_source_identity (
    singleton TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    source_instance_id CHAR(36) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT chk_sync_source_identity_singleton CHECK (singleton = 1),
    UNIQUE KEY uq_sync_source_instance_id (source_instance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sync_source_identity (singleton, source_instance_id)
SELECT 1, LOWER(business_id)
FROM pa_integration_identity
WHERE singleton = 1;

INSERT IGNORE INTO sync_source_identity (singleton, source_instance_id)
VALUES (1, LOWER(UUID()));

CREATE TABLE IF NOT EXISTS sync_resource_state (
    resource_type VARCHAR(64) NOT NULL,
    resource_id VARCHAR(191) NOT NULL,
    resource_version BIGINT UNSIGNED NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    present TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (resource_type, resource_id),
    INDEX idx_sync_resource_updated (updated_at),
    CONSTRAINT chk_sync_resource_version CHECK (resource_version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_event_log (
    sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id CHAR(36) NOT NULL,
    source_instance_id CHAR(36) NOT NULL,
    resource_type VARCHAR(64) NOT NULL,
    resource_id VARCHAR(191) NOT NULL,
    resource_version BIGINT UNSIGNED NOT NULL,
    action VARCHAR(32) NOT NULL,
    payload JSON NULL,
    occurred_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_sync_event_id (event_id),
    INDEX idx_sync_event_resource (resource_type, resource_id, resource_version),
    INDEX idx_sync_event_occurred (occurred_at),
    CONSTRAINT fk_sync_event_source
        FOREIGN KEY (source_instance_id) REFERENCES sync_source_identity(source_instance_id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_sync_event_version CHECK (resource_version >= 1),
    CONSTRAINT chk_sync_event_action CHECK (action IN ('upsert', 'delete'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_snapshot_sessions (
    snapshot_id CHAR(36) NOT NULL PRIMARY KEY,
    source_instance_id CHAR(36) NOT NULL,
    api_key_id INT NOT NULL,
    high_water_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    generated_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_sync_snapshot_expiry (expires_at),
    INDEX idx_sync_snapshot_key (api_key_id, expires_at),
    CONSTRAINT fk_sync_snapshot_source
        FOREIGN KEY (source_instance_id) REFERENCES sync_source_identity(source_instance_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_sync_snapshot_api_key
        FOREIGN KEY (api_key_id) REFERENCES api_keys(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
