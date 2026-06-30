CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    name VARCHAR(255) NOT NULL,
    key_prefix VARCHAR(32) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    scopes VARCHAR(1024) NULL,
    allowed_ips TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL,
    revoked_at TIMESTAMP NULL,
    UNIQUE KEY uq_key_hash (key_hash),
    INDEX idx_api_keys_prefix (key_prefix),
    INDEX idx_api_keys_revoked (revoked_at),
    INDEX idx_api_keys_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @api_keys_item_name_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'item_name'
);

SET @api_keys_name_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'name'
);

SET @sql := IF(
    @api_keys_item_name_exists = 1 AND @api_keys_name_exists = 0,
    'ALTER TABLE api_keys CHANGE COLUMN item_name name VARCHAR(255) NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @api_keys_name_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'name'
);

SET @sql := IF(
    @api_keys_name_exists = 0,
    'ALTER TABLE api_keys ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT '''' AFTER id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @api_keys_org_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'api_keys'
      AND column_name = 'organization_id'
);

SET @sql := IF(
    @api_keys_org_exists = 0,
    'ALTER TABLE api_keys ADD COLUMN organization_id INT NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS api_usage (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT NOT NULL,
    used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_usage_key_time (api_key_id, used_at),
    CONSTRAINT fk_api_usage_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @invoice_collection_mode_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'invoices'
      AND column_name = 'collection_mode'
);

SET @sql := IF(
    @invoice_collection_mode_exists = 0,
    'ALTER TABLE invoices ADD COLUMN collection_mode ENUM(''direct'',''project_aggregate'') NOT NULL DEFAULT ''direct'' AFTER finalization_source',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS client_onboarding_invitations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    target_organization_id INT NULL,
    client_id INT NULL,
    invited_email VARCHAR(255) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    status ENUM('pending','verified','submitted','approved','rejected','revoked','expired') NOT NULL DEFAULT 'pending',
    expires_at DATETIME NOT NULL,
    verification_code_hash VARCHAR(255) NULL,
    code_expires_at DATETIME NULL,
    verification_attempts SMALLINT NOT NULL DEFAULT 0,
    last_code_sent_at DATETIME NULL,
    email_verified_at DATETIME NULL,
    consumed_at DATETIME NULL,
    sent_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_onboarding_token (token_hash),
    INDEX idx_client_onboarding_owner (organization_id,status,created_at),
    INDEX idx_client_onboarding_email (invited_email),
    CONSTRAINT fk_client_onboarding_owner FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_onboarding_target_org FOREIGN KEY (target_organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_onboarding_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_onboarding_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_onboarding_submissions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invitation_id BIGINT NOT NULL,
    proposed_data JSON NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_notes VARCHAR(1000) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_onboarding_submission (invitation_id),
    INDEX idx_client_onboarding_review (status,created_at),
    CONSTRAINT fk_client_onboarding_submission_invite FOREIGN KEY (invitation_id) REFERENCES client_onboarding_invitations(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_onboarding_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
