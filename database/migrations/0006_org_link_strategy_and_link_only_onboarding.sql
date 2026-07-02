ALTER TABLE organizations
    ADD COLUMN link_strategy ENUM('department_links_only','overall_folder','shared_folder') NOT NULL DEFAULT 'department_links_only'
    AFTER tax_exempt_uploaded_at;

ALTER TABLE client_onboarding_invitations
    MODIFY invited_email VARCHAR(255) NULL;
