-- Add defaults for payment receipt and automated email notice settings.

INSERT INTO app_config (organization_id, config_key, config_value)
VALUES
    (0, 'payment_receipts_enabled', '1'),
    (0, 'email_no_reply_notice_enabled', '0'),
    (0, 'email_no_reply_notice_text', 'This is an automated message. Please do not reply to this email.')
ON DUPLICATE KEY UPDATE config_value = config_value;
