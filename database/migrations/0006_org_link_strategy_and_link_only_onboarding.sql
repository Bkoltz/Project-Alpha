SET @org_link_strategy_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'organizations'
      AND column_name = 'link_strategy'
);

SET @org_link_strategy_sql := IF(
    @org_link_strategy_exists = 0,
    'ALTER TABLE organizations ADD COLUMN link_strategy ENUM(''department_links_only'',''overall_folder'',''shared_folder'') NOT NULL DEFAULT ''department_links_only'' AFTER tax_exempt_uploaded_at',
    'SELECT 1'
);

PREPARE org_link_strategy_stmt FROM @org_link_strategy_sql;
EXECUTE org_link_strategy_stmt;
DEALLOCATE PREPARE org_link_strategy_stmt;

ALTER TABLE client_onboarding_invitations
    MODIFY invited_email VARCHAR(255) NULL;
