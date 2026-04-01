-- Migration: Create app_config table for global settings
-- Run in Docker:
--   docker compose exec web mysql -u root -p project_alpha < /var/www/src/migrations/create_app_config_table.sql

CREATE TABLE IF NOT EXISTS app_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_config_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default values
INSERT INTO app_config (config_key, config_value) VALUES
('link_resolver_enabled', '0'),
('default_link_expiration_days', '365'),
('org_level_links_only', '0'),
('link_expiration_checker', '0'),
('link_expiration_email_enabled', '0')
ON DUPLICATE KEY UPDATE config_value = config_value;
