-- Pay-period deadline reminders and idempotent automatic confirmation.

INSERT INTO app_config (organization_id,config_key,config_value) VALUES
    (0,'workforce_period_deadline_time','20:00'),
    (0,'workforce_period_auto_confirm','1')
ON DUPLICATE KEY UPDATE config_value=config_value;
-- Existing organization choices win when a deployment retries this migration.
-- The insert defaults apply only when the setting does not exist yet.

CREATE TABLE IF NOT EXISTS workforce_deadline_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    pay_period_id BIGINT NOT NULL,
    worker_profile_id INT NOT NULL,
    event_type ENUM('reminder_4h','reminder_2h','reminder_1h','auto_confirm') NOT NULL,
    status ENUM('pending','completed','failed','skipped') NOT NULL DEFAULT 'pending',
    scheduled_for DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    details JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_workforce_deadline_event (pay_period_id,worker_profile_id,event_type),
    INDEX idx_workforce_deadline_due (status,scheduled_for),
    CONSTRAINT fk_workforce_deadline_period FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_workforce_deadline_worker FOREIGN KEY (worker_profile_id) REFERENCES worker_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cron_job_runs (job_name,last_run,status)
VALUES ('process_workforce_deadlines',NULL,'success');
