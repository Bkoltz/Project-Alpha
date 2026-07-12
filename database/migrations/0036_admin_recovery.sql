-- Operator recovery invalidates existing sessions by advancing auth_version.
-- A separate flag makes lost-TOTP recovery require fresh enrollment after the
-- temporary password has been changed.

SET @auth_version_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='auth_version');
SET @sql := IF(@auth_version_exists=0, 'ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER force_password_reset', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @totp_reenroll_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='totp_reenroll_required');
SET @sql := IF(@totp_reenroll_exists=0, 'ALTER TABLE users ADD COLUMN totp_reenroll_required TINYINT(1) NOT NULL DEFAULT 0 AFTER auth_version', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
