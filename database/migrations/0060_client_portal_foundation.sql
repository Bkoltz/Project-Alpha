-- Provider-neutral, default-off client portal foundation.
-- Identity provider enrollment, routes, invitations, and email are intentionally out of scope.

ALTER TABLE organizations
    ADD COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id;
ALTER TABLE clients
    ADD COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id;
ALTER TABLE projects
    ADD COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id;

UPDATE organizations SET public_id = LOWER(HEX(RANDOM_BYTES(16))) WHERE public_id IS NULL;
UPDATE clients SET public_id = LOWER(HEX(RANDOM_BYTES(16))) WHERE public_id IS NULL;
UPDATE projects SET public_id = LOWER(HEX(RANDOM_BYTES(16))) WHERE public_id IS NULL;

ALTER TABLE organizations
    MODIFY COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16)))),
    ADD UNIQUE KEY uq_organizations_public_id (public_id);
ALTER TABLE clients
    MODIFY COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16)))),
    ADD UNIQUE KEY uq_clients_public_id (public_id);
ALTER TABLE projects
    MODIFY COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16)))),
    ADD UNIQUE KEY uq_projects_public_id (public_id);

INSERT INTO app_config (organization_id, config_key, config_value)
VALUES (0, 'client_portal_enabled', '0')
ON DUPLICATE KEY UPDATE config_value = '0';

CREATE TABLE portal_principals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16)))),
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    authorization_version INT UNSIGNED NOT NULL DEFAULT 1,
    activated_at DATETIME NULL, revoked_at DATETIME NULL,
    created_by INT NULL, updated_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_portal_principals_public_id (public_id), KEY idx_portal_principals_state (enabled, revoked_at),
    CONSTRAINT fk_portal_principals_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_portal_principals_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE portal_principal_clients (
    portal_principal_id BIGINT UNSIGNED NOT NULL, client_id INT NOT NULL, created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (portal_principal_id, client_id), KEY idx_portal_principal_clients_client (client_id),
    CONSTRAINT fk_portal_principal_clients_principal FOREIGN KEY (portal_principal_id) REFERENCES portal_principals(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_principal_clients_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_principal_clients_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE portal_identity_bindings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, portal_principal_id BIGINT UNSIGNED NOT NULL,
    issuer VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    subject_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 0,
    bound_at DATETIME NULL, revoked_at DATETIME NULL, created_by INT NULL, updated_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_portal_identity_issuer_subject (issuer, subject_hash), KEY idx_portal_identity_principal_state (portal_principal_id, enabled, revoked_at),
    CONSTRAINT fk_portal_identity_principal FOREIGN KEY (portal_principal_id) REFERENCES portal_principals(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_identity_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_portal_identity_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE portal_organization_entitlements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, portal_principal_id BIGINT UNSIGNED NOT NULL, organization_id INT NOT NULL,
    capability VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 0,
    starts_at DATETIME NULL, expires_at DATETIME NULL, revoked_at DATETIME NULL, created_by INT NULL, updated_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_portal_org_entitlement (portal_principal_id, organization_id, capability), KEY idx_portal_org_entitlement_lookup (organization_id, capability, enabled, revoked_at),
    CONSTRAINT fk_portal_org_entitlement_principal FOREIGN KEY (portal_principal_id) REFERENCES portal_principals(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_org_entitlement_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_org_entitlement_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_portal_org_entitlement_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE portal_project_entitlements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, portal_principal_id BIGINT UNSIGNED NOT NULL, project_id INT NOT NULL,
    capability VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 0,
    starts_at DATETIME NULL, expires_at DATETIME NULL, revoked_at DATETIME NULL, created_by INT NULL, updated_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_portal_project_entitlement (portal_principal_id, project_id, capability), KEY idx_portal_project_entitlement_lookup (project_id, capability, enabled, revoked_at),
    CONSTRAINT fk_portal_project_entitlement_principal FOREIGN KEY (portal_principal_id) REFERENCES portal_principals(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_project_entitlement_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_project_entitlement_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_portal_project_entitlement_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
