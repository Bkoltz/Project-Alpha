-- Migration 016: Webhook Event Log
-- ============================================================================
-- Purpose: Add a dedicated audit table for Stripe webhook delivery attempts.
--   This table captures every request before validation, and again after
--   validation, so we can diagnose signature/timing/secret mismatches from the
--   database rather than relying solely on container logs.
-- Date: 2026-06-17
-- ============================================================================

USE project_alpha;

CREATE TABLE IF NOT EXISTS webhook_event_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(100) NOT NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    event_type VARCHAR(100) NULL,
    event_id VARCHAR(100) NULL,
    signature_present TINYINT(1) NOT NULL DEFAULT 0,
    signature_valid TINYINT(1) NULL,
    ip_address VARCHAR(45) NULL,
    payload_size INT NOT NULL DEFAULT 0,
    http_response_code INT NULL,
    error_message TEXT NULL,
    raw_payload LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_webhook_event_log_received_at (received_at),
    INDEX idx_webhook_event_log_endpoint (endpoint),
    INDEX idx_webhook_event_log_event_id (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
