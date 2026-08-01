CREATE TABLE IF NOT EXISTS notification_relay_key_state (
    api_key_id INT NOT NULL PRIMARY KEY,
    active_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_relay_state_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_relay_rate_buckets (
    api_key_id INT NOT NULL,
    bucket_type ENUM('key_minute', 'recipient_hour') NOT NULL,
    subject_hash CHAR(64) NOT NULL,
    window_key BIGINT UNSIGNED NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (api_key_id, bucket_type, subject_hash, window_key),
    INDEX idx_notification_relay_rate_window (bucket_type, window_key),
    CONSTRAINT fk_notification_relay_rate_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_relay_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT NOT NULL,
    action_name VARCHAR(64) NOT NULL,
    template_name VARCHAR(64) NOT NULL,
    recipient_alias VARCHAR(64) NOT NULL,
    recipient_email VARCHAR(254) NOT NULL,
    recipient_hash CHAR(64) NOT NULL,
    variables_json JSON NOT NULL,
    idempotency_hash CHAR(64) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    status ENUM('pending', 'processing', 'retry', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at TIMESTAMP NULL,
    lock_token CHAR(32) NULL,
    sent_at TIMESTAMP NULL,
    last_error_code VARCHAR(64) NULL,
    source_ip VARCHAR(45) NULL,
    source_user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notification_relay_idempotency (api_key_id, idempotency_hash),
    INDEX idx_notification_relay_due (status, next_attempt_at, id),
    INDEX idx_notification_relay_recipient (recipient_hash, created_at),
    CONSTRAINT fk_notification_relay_queue_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_relay_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    queue_id BIGINT NULL,
    queue_reference BIGINT NULL,
    api_key_id INT NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    status VARCHAR(32) NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    error_code VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notification_relay_event_queue (queue_reference, created_at),
    INDEX idx_notification_relay_event_key (api_key_id, created_at),
    CONSTRAINT fk_notification_relay_event_queue FOREIGN KEY (queue_id) REFERENCES notification_relay_queue(id) ON DELETE SET NULL,
    CONSTRAINT fk_notification_relay_event_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
