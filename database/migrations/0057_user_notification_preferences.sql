-- Migration 0057: Per-user notification preferences
-- Stores each user's opt-in/out choices for automated email notifications.
-- notify_processor_invoice_paid controls whether the user receives an email
-- when a client pays via Stripe or another automatic processor. Manual
-- payments never trigger this notification.
-- Default is 1 (opted in) so existing users keep their current behaviour.

CREATE TABLE IF NOT EXISTS user_notification_preferences (
    user_id    INT          NOT NULL,
    notify_processor_invoice_paid TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_unp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
