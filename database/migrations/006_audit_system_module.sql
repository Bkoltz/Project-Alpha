-- ============================================================================
-- Module 006: Audit, Notifications & System
-- ============================================================================
-- System audit logs, scheduled audits, notification settings, cron tracking,
-- app configuration
-- ============================================================================

USE project_alpha;

-- ============================================================================
-- SYSTEM AUDIT LOG (General activity tracking)
-- ============================================================================
CREATE TABLE IF NOT EXISTS system_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    organization_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id INT NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_org (organization_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- AUDIT SCHEDULES (Scheduled audit/review tasks)
-- ============================================================================
CREATE TABLE IF NOT EXISTS audit_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    frequency ENUM('daily', 'weekly', 'monthly', 'quarterly', 'yearly') NOT NULL DEFAULT 'monthly',
    last_run_at TIMESTAMP NULL,
    next_run_at TIMESTAMP NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_audit_sched_org (organization_id),
    INDEX idx_audit_sched_active (is_active),
    INDEX idx_audit_sched_next (next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- AUDIT SCHEDULE LOGS (Execution history)
-- ============================================================================
CREATE TABLE IF NOT EXISTS audit_schedule_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    status ENUM('pending', 'running', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    result_summary TEXT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_log_schedule (schedule_id),
    INDEX idx_audit_log_status (status),
    CONSTRAINT fk_audit_log_schedule FOREIGN KEY (schedule_id) REFERENCES audit_schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- NOTIFICATION SETTINGS (Per-user preferences)
-- ============================================================================
CREATE TABLE IF NOT EXISTS notification_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    organization_id INT NULL,
    notification_type VARCHAR(100) NOT NULL,
    email_enabled TINYINT(1) NOT NULL DEFAULT 1,
    sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
    push_enabled TINYINT(1) NOT NULL DEFAULT 0,
    digest_frequency ENUM('immediate', 'hourly', 'daily', 'weekly') NOT NULL DEFAULT 'immediate',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notif_settings (user_id, organization_id, notification_type),
    CONSTRAINT fk_notif_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- NOTIFICATION LOG (Delivery history)
-- ============================================================================
CREATE TABLE IF NOT EXISTS notification_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    organization_id INT NULL,
    notification_type VARCHAR(100) NOT NULL,
    channel ENUM('email', 'sms', 'push', 'in_app') NOT NULL DEFAULT 'email',
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NULL,
    body TEXT NULL,
    status ENUM('pending', 'sent', 'failed', 'bounced') NOT NULL DEFAULT 'pending',
    sent_at TIMESTAMP NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_log_user (user_id),
    INDEX idx_notif_log_org (organization_id),
    INDEX idx_notif_log_type (notification_type),
    INDEX idx_notif_log_status (status),
    INDEX idx_notif_log_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- IN-APP NOTIFICATIONS
-- ============================================================================
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    organization_id INT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NULL,
    link VARCHAR(255) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user (user_id),
    INDEX idx_notifications_read (is_read),
    INDEX idx_notifications_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CRON JOB RUNS
-- ============================================================================
CREATE TABLE IF NOT EXISTS cron_job_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL,
    last_run DATETIME NULL,
    status ENUM('running', 'completed', 'failed', 'success') NOT NULL DEFAULT 'running',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    result TEXT NULL,
    error_message TEXT NULL,
    UNIQUE KEY uq_cron_job_name (job_name),
    INDEX idx_cron_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- APP CONFIGURATION (Key-Value settings)
-- ============================================================================
CREATE TABLE IF NOT EXISTS app_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL DEFAULT 0,
    config_key VARCHAR(100) NOT NULL,
    config_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_app_config (organization_id, config_key),
    INDEX idx_config_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
