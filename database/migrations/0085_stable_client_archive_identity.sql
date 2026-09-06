-- Preserve the immutable client and portal identity across the legacy physical
-- archive/restore workflow. Existing archive rows remain restorable as legacy
-- records; newly archived rows always carry the complete identity state.

ALTER TABLE archived_clients
    ADD COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER client_id,
    ADD COLUMN client_type ENUM('unknown','business','consumer') NULL AFTER organization_id,
    ADD COLUMN portal_principal_id BIGINT UNSIGNED NULL AFTER created_at,
    ADD COLUMN portal_manual_state ENUM('automatic','revoked') NULL AFTER portal_principal_id,
    ADD COLUMN portal_canonical_email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NULL AFTER portal_manual_state,
    ADD COLUMN portal_identity_binding_ids_json JSON NULL AFTER portal_canonical_email,
    ADD COLUMN portal_principal_authorization_version INT UNSIGNED NULL AFTER portal_identity_binding_ids_json,
    ADD COLUMN portal_principal_disabled_for_archive TINYINT(1) NULL AFTER portal_principal_authorization_version,
    ADD COLUMN portal_principal_was_present TINYINT(1) NOT NULL DEFAULT 0 AFTER portal_principal_disabled_for_archive,
    ADD COLUMN portal_entitlement_ids_json JSON NULL AFTER portal_principal_was_present,
    ADD COLUMN portal_affected_workspace_ids_json JSON NULL AFTER portal_entitlement_ids_json,
    ADD UNIQUE KEY uq_archived_clients_public_id (public_id),
    ADD KEY idx_archived_clients_portal_principal (portal_principal_id),
    ADD CONSTRAINT fk_archived_clients_portal_principal
        FOREIGN KEY (portal_principal_id) REFERENCES portal_principals(id) ON DELETE SET NULL;
