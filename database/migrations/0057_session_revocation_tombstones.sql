-- Preserve revoked session identifiers long enough to prevent delayed requests
-- from recreating authenticated state after logout, expiry, or rotation.
ALTER TABLE app_sessions
    ADD COLUMN revoked_at DATETIME(6) NULL AFTER absolute_expires_at,
    ADD INDEX idx_app_sessions_revoked (revoked_at);
