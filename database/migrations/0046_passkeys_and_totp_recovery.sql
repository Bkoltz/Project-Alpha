-- Passwordless WebAuthn credentials, one-time ceremonies, and removal of
-- application backup codes. TOTP and audited operator recovery remain.

CREATE TABLE IF NOT EXISTS passkey_credentials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    credential_id VARBINARY(1024) NOT NULL,
    user_handle VARBINARY(64) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    credential_record LONGTEXT NOT NULL,
    signature_counter BIGINT UNSIGNED NOT NULL DEFAULT 0,
    transports JSON NULL,
    aaguid CHAR(36) NULL,
    backup_eligible TINYINT(1) NULL,
    backup_status TINYINT(1) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    revoked_by INT NULL,
    UNIQUE KEY uq_passkey_credential_id (credential_id),
    INDEX idx_passkey_user_active (user_id,revoked_at),
    INDEX idx_passkey_user_handle (user_handle),
    CONSTRAINT fk_passkey_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_passkey_revoker FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_passkey_record_json CHECK (JSON_VALID(credential_record)),
    CONSTRAINT chk_passkey_display_name CHECK (CHAR_LENGTH(TRIM(display_name)) BETWEEN 1 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS passkey_challenges (
    id CHAR(64) PRIMARY KEY,
    user_id INT NULL,
    ceremony ENUM('registration','authentication') NOT NULL,
    challenge_hash BINARY(32) NOT NULL,
    session_hash BINARY(32) NOT NULL,
    context_json JSON NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_passkey_challenge_expiry (expires_at,consumed_at),
    INDEX idx_passkey_challenge_user (user_id,ceremony,created_at),
    CONSTRAINT fk_passkey_challenge_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS passkey_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    ip_address VARCHAR(45) NOT NULL,
    ceremony ENUM('registration','authentication','management') NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    failure_code VARCHAR(80) NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_passkey_attempt_ip (ip_address,ceremony,attempted_at),
    INDEX idx_passkey_attempt_user (user_id,ceremony,attempted_at),
    CONSTRAINT fk_passkey_attempt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Previously issued application recovery codes must stop working as soon as
-- this migration is applied. The Docker/operator recovery path is separate.
SET @has_backup_codes := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='user_2fa' AND column_name='backup_codes'
);
SET @sql := IF(
    @has_backup_codes=1,
    'UPDATE user_2fa SET backup_codes = NULL WHERE backup_codes IS NOT NULL',
    'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(
    @has_backup_codes=1,
    'ALTER TABLE user_2fa DROP COLUMN backup_codes',
    'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Challenge rows are intentionally ephemeral. Startup/cron cleanup may delete
-- consumed or expired rows after this retention window.
DELETE FROM passkey_challenges
WHERE consumed_at IS NOT NULL OR expires_at < UTC_TIMESTAMP();
