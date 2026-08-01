SET @invoice_notification_columns := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'invoice_notifications'
      AND column_name = 'delivery_key'
);
SET @sql := IF(
    @invoice_notification_columns = 0,
    'ALTER TABLE invoice_notifications
        ADD COLUMN delivery_key VARCHAR(100) NOT NULL DEFAULT ''default'' AFTER notification_type,
        ADD COLUMN recipient_key CHAR(64) NOT NULL DEFAULT '''' AFTER delivery_key,
        ADD COLUMN delivery_status ENUM(''pending'',''processing'',''retry'',''sent'',''suppressed'') NOT NULL DEFAULT ''sent'' AFTER recipient_key,
        ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER delivery_status,
        ADD COLUMN next_attempt_at DATETIME NULL AFTER attempt_count,
        ADD COLUMN last_attempt_at DATETIME NULL AFTER next_attempt_at,
        ADD COLUMN claimed_at DATETIME NULL AFTER last_attempt_at,
        ADD COLUMN last_error TEXT NULL AFTER claimed_at,
        ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
    'SELECT 1'
);
PREPARE invoice_notification_columns_stmt FROM @sql;
EXECUTE invoice_notification_columns_stmt;
DEALLOCATE PREPARE invoice_notification_columns_stmt;

UPDATE invoice_notifications
SET recipient_key = SHA2(LOWER(TRIM(COALESCE(email_to, ''))), 256)
WHERE recipient_key = '';

UPDATE invoice_notifications
SET delivery_status = IF(sent_at IS NULL, 'retry', 'sent'),
    next_attempt_at = IF(sent_at IS NULL, NOW(), NULL),
    last_error = IF(sent_at IS NULL, 'Recovered legacy delivery claim without a sent timestamp.', last_error)
WHERE delivery_status = 'sent';

SET @old_invoice_notification_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'invoice_notifications'
      AND index_name = 'uq_invoice_notification'
);
SET @sql := IF(@old_invoice_notification_index > 0,
    'ALTER TABLE invoice_notifications DROP INDEX uq_invoice_notification', 'SELECT 1');
PREPARE old_invoice_notification_index_stmt FROM @sql;
EXECUTE old_invoice_notification_index_stmt;
DEALLOCATE PREPARE old_invoice_notification_index_stmt;

SET @invoice_notification_delivery_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'invoice_notifications'
      AND index_name = 'uq_invoice_notification_delivery'
);
SET @sql := IF(@invoice_notification_delivery_index = 0,
    'ALTER TABLE invoice_notifications ADD UNIQUE KEY uq_invoice_notification_delivery (invoice_id, notification_type, delivery_key, recipient_key)',
    'SELECT 1');
PREPARE invoice_notification_delivery_index_stmt FROM @sql;
EXECUTE invoice_notification_delivery_index_stmt;
DEALLOCATE PREPARE invoice_notification_delivery_index_stmt;

SET @invoice_notification_due_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'invoice_notifications'
      AND index_name = 'idx_inv_notif_delivery_due'
);
SET @sql := IF(@invoice_notification_due_index = 0,
    'ALTER TABLE invoice_notifications ADD KEY idx_inv_notif_delivery_due (delivery_status, next_attempt_at)',
    'SELECT 1');
PREPARE invoice_notification_due_index_stmt FROM @sql;
EXECUTE invoice_notification_due_index_stmt;
DEALLOCATE PREPARE invoice_notification_due_index_stmt;

SET @project_invoice_notification_columns := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'project_invoice_notifications'
      AND column_name = 'delivery_key'
);
SET @sql := IF(
    @project_invoice_notification_columns = 0,
    'ALTER TABLE project_invoice_notifications
        ADD COLUMN delivery_key VARCHAR(100) NOT NULL DEFAULT ''default'' AFTER notification_type,
        ADD COLUMN recipient_key CHAR(64) NOT NULL DEFAULT '''' AFTER delivery_key,
        ADD COLUMN delivery_status ENUM(''pending'',''processing'',''retry'',''sent'',''suppressed'') NOT NULL DEFAULT ''sent'' AFTER recipient_key,
        ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER delivery_status,
        ADD COLUMN next_attempt_at DATETIME NULL AFTER attempt_count,
        ADD COLUMN last_attempt_at DATETIME NULL AFTER next_attempt_at,
        ADD COLUMN claimed_at DATETIME NULL AFTER last_attempt_at,
        ADD COLUMN last_error TEXT NULL AFTER claimed_at,
        ADD COLUMN email_subject VARCHAR(255) NULL AFTER email_to,
        ADD COLUMN email_body TEXT NULL AFTER email_subject,
        ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
    'SELECT 1'
);
PREPARE project_invoice_notification_columns_stmt FROM @sql;
EXECUTE project_invoice_notification_columns_stmt;
DEALLOCATE PREPARE project_invoice_notification_columns_stmt;

UPDATE project_invoice_notifications
SET recipient_key = SHA2(LOWER(TRIM(email_to)), 256)
WHERE recipient_key = '';

UPDATE project_invoice_notifications
SET delivery_status = IF(sent_at IS NULL, 'retry', 'sent'),
    next_attempt_at = IF(sent_at IS NULL, NOW(), NULL),
    last_error = IF(sent_at IS NULL, 'Recovered legacy delivery row without a sent timestamp.', last_error)
WHERE delivery_status = 'sent';

SET @old_project_invoice_notification_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'project_invoice_notifications'
      AND index_name = 'uq_project_invoice_notification'
);
SET @sql := IF(@old_project_invoice_notification_index > 0,
    'ALTER TABLE project_invoice_notifications DROP INDEX uq_project_invoice_notification', 'SELECT 1');
PREPARE old_project_invoice_notification_index_stmt FROM @sql;
EXECUTE old_project_invoice_notification_index_stmt;
DEALLOCATE PREPARE old_project_invoice_notification_index_stmt;

SET @project_invoice_notification_delivery_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'project_invoice_notifications'
      AND index_name = 'uq_project_invoice_notification_delivery'
);
SET @sql := IF(@project_invoice_notification_delivery_index = 0,
    'ALTER TABLE project_invoice_notifications ADD UNIQUE KEY uq_project_invoice_notification_delivery (project_invoice_id, notification_type, delivery_key, recipient_key)',
    'SELECT 1');
PREPARE project_invoice_notification_delivery_index_stmt FROM @sql;
EXECUTE project_invoice_notification_delivery_index_stmt;
DEALLOCATE PREPARE project_invoice_notification_delivery_index_stmt;

SET @project_invoice_notification_due_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'project_invoice_notifications'
      AND index_name = 'idx_project_invoice_notif_due'
);
SET @sql := IF(@project_invoice_notification_due_index = 0,
    'ALTER TABLE project_invoice_notifications ADD KEY idx_project_invoice_notif_due (delivery_status, next_attempt_at)',
    'SELECT 1');
PREPARE project_invoice_notification_due_index_stmt FROM @sql;
EXECUTE project_invoice_notification_due_index_stmt;
DEALLOCATE PREPARE project_invoice_notification_due_index_stmt;