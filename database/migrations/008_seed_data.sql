-- ============================================================================
-- Module 008: Seed Data
-- ============================================================================
-- Default organization, admin user, and initial app config
-- ============================================================================

USE project_alpha;

-- ============================================================================
-- DEFAULT ORGANIZATION
-- ============================================================================
INSERT INTO organizations (name) VALUES ('Default Organization')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ============================================================================
-- DEFAULT ADMIN USER
-- Password: admin123 (hash will be replaced by docker/start.sh)
-- ============================================================================
INSERT INTO users (email, password_hash, username, role, force_password_reset)
VALUES ('admin@project-alpha.local', '{{ADMIN_PASSWORD_HASH}}', 'admin', 'admin', 0)
ON DUPLICATE KEY UPDATE email = VALUES(email);

-- ============================================================================
-- LINK ADMIN TO DEFAULT ORGANIZATION
-- ============================================================================
INSERT INTO user_organizations (user_id, organization_id, role, is_default)
VALUES (1, 1, 'owner', 1)
ON DUPLICATE KEY UPDATE role = VALUES(role), is_default = VALUES(is_default);

-- ============================================================================
-- DEFAULT APP CONFIG
-- ============================================================================
INSERT INTO app_config (config_key, config_value) VALUES
    ('brand_name', 'Project Alpha'),
    ('timezone', 'UTC'),
    ('primary_state', '')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);
