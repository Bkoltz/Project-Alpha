-- Migration 037: Secure, invitation-only client onboarding portal.
USE project_alpha;

CREATE TABLE IF NOT EXISTS client_onboarding_invitations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    target_organization_id INT NULL,
    client_id INT NULL,
    invited_email VARCHAR(255) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    status ENUM('pending','verified','submitted','approved','rejected','revoked','expired') NOT NULL DEFAULT 'pending',
    expires_at DATETIME NOT NULL,
    verification_code_hash VARCHAR(255) NULL,
    code_expires_at DATETIME NULL,
    verification_attempts SMALLINT NOT NULL DEFAULT 0,
    last_code_sent_at DATETIME NULL,
    email_verified_at DATETIME NULL,
    consumed_at DATETIME NULL,
    sent_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_onboarding_token (token_hash),
    INDEX idx_client_onboarding_owner (organization_id,status,created_at),
    INDEX idx_client_onboarding_email (invited_email),
    CONSTRAINT fk_client_onboarding_owner FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_onboarding_target_org FOREIGN KEY (target_organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_onboarding_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_onboarding_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_onboarding_submissions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invitation_id BIGINT NOT NULL,
    proposed_data JSON NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_notes VARCHAR(1000) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_onboarding_submission (invitation_id),
    INDEX idx_client_onboarding_review (status,created_at),
    CONSTRAINT fk_client_onboarding_submission_invite FOREIGN KEY (invitation_id) REFERENCES client_onboarding_invitations(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_onboarding_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
