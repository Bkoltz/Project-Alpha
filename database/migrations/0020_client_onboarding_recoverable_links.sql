SET @client_onboarding_token_enc_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'client_onboarding_invitations'
      AND column_name = 'token_enc'
);

SET @client_onboarding_token_enc_sql := IF(
    @client_onboarding_token_enc_exists = 0,
    'ALTER TABLE client_onboarding_invitations ADD COLUMN token_enc TEXT NULL AFTER token_hash',
    'SELECT 1'
);

PREPARE client_onboarding_token_enc_stmt FROM @client_onboarding_token_enc_sql;
EXECUTE client_onboarding_token_enc_stmt;
DEALLOCATE PREPARE client_onboarding_token_enc_stmt;

UPDATE client_onboarding_invitations
SET status = 'revoked', consumed_at = COALESCE(consumed_at, NOW())
WHERE status IN ('pending','verified')
  AND created_at < DATE_SUB(NOW(), INTERVAL 14 DAY);
