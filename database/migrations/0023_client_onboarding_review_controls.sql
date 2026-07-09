SET @client_onboarding_notify_on_submit_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'client_onboarding_invitations'
      AND column_name = 'notify_on_submit'
);

SET @client_onboarding_notify_on_submit_sql := IF(
    @client_onboarding_notify_on_submit_exists = 0,
    'ALTER TABLE client_onboarding_invitations ADD COLUMN notify_on_submit TINYINT(1) NOT NULL DEFAULT 1 AFTER sent_at',
    'SELECT 1'
);

PREPARE client_onboarding_notify_on_submit_stmt FROM @client_onboarding_notify_on_submit_sql;
EXECUTE client_onboarding_notify_on_submit_stmt;
DEALLOCATE PREPARE client_onboarding_notify_on_submit_stmt;

ALTER TABLE client_onboarding_invitations
    MODIFY COLUMN notify_on_submit TINYINT(1) NOT NULL DEFAULT 1;

INSERT INTO app_config (organization_id, config_key, config_value)
VALUES (0, 'notify_client_onboarding_submit', '1')
ON DUPLICATE KEY UPDATE config_value = app_config.config_value;
