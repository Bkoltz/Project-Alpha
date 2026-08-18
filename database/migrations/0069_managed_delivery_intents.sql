-- Default-off managed delivery handoff. Project Alpha records only opaque
-- business scope identifiers and a neutral receiver receipt; storage paths,
-- object credentials, recipient email addresses, and bearer URLs stay in the
-- external delivery service.

CREATE TABLE IF NOT EXISTS managed_delivery_intent_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    delivery_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    intent_type ENUM('provision','revoke') NOT NULL DEFAULT 'provision',
    target_delivery_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    integration_profile_id BIGINT UNSIGNED NOT NULL,
    destination_url VARCHAR(500) NOT NULL,
    pinned_application_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    signing_key_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    signing_contract_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    delivery_timeout_seconds SMALLINT UNSIGNED NOT NULL,
    delivery_max_attempts SMALLINT UNSIGNED NOT NULL,
    actor_user_id INT NULL,
    scope_type ENUM('organization','department','client','project') NOT NULL,
    scope_public_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    audience_type ENUM('principal') NOT NULL,
    audience_public_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    access_mode ENUM('portal','guest') NOT NULL DEFAULT 'portal',
    request_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_json JSON NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    claim_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    claimed_at DATETIME(6) NULL,
    delivered_at DATETIME(6) NULL,
    dead_lettered_at DATETIME(6) NULL,
    last_http_status SMALLINT UNSIGNED NULL,
    last_error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    receipt_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    revoked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_managed_delivery_id (delivery_id),
    KEY idx_managed_delivery_dispatch (delivered_at,dead_lettered_at,next_attempt_at,claimed_at,id),
    KEY idx_managed_delivery_scope (scope_type,scope_public_id,created_at),
    KEY idx_managed_delivery_target (target_delivery_id,intent_type,delivered_at),
    KEY idx_managed_delivery_profile (integration_profile_id,created_at),
    UNIQUE KEY uq_managed_delivery_revocation (target_delivery_id,intent_type),
    CONSTRAINT fk_managed_delivery_target FOREIGN KEY (target_delivery_id) REFERENCES managed_delivery_intent_outbox(delivery_id) ON DELETE RESTRICT,
    CONSTRAINT fk_managed_delivery_profile FOREIGN KEY (integration_profile_id) REFERENCES portal_integration_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_managed_delivery_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_config (organization_id,config_key,config_value) VALUES
    (0,'managed_delivery_enabled','0'),
    (0,'managed_delivery_intent_url',''),
    (0,'managed_delivery_profile_id','0'),
    (0,'managed_delivery_guest_links_enabled','0')
ON DUPLICATE KEY UPDATE config_value=config_value;
