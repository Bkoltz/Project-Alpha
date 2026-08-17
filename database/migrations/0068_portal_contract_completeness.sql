-- Migration 0068: complete the default-off portal command/audit and incremental projection contracts.
-- No integration capability is enabled by this migration.

ALTER TABLE portal_integration_audit
    MODIFY COLUMN integration_profile_id BIGINT UNSIGNED NULL;

-- Distinct, opt-in Viewer share authority. Applying this migration creates no
-- entitlement and does not expand the default manager capability set.
ALTER TABLE portal_v2_entitlements
    DROP CHECK chk_portal_v2_entitlement_capability,
    ADD CONSTRAINT chk_portal_v2_entitlement_capability CHECK (capability IN ('workspace.view','directory.read','request.create','delivery.view','member.manage','delegated_share.create','viewer.share.create'));

SET @portal_complete_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='portal_integration_audit' AND column_name='correlation_id')=0,'ALTER TABLE portal_integration_audit ADD COLUMN correlation_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER api_key_id','SELECT 1');PREPARE portal_complete_stmt FROM @portal_complete_sql;EXECUTE portal_complete_stmt;DEALLOCATE PREPARE portal_complete_stmt;
SET @portal_complete_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='portal_integration_audit' AND column_name='outcome')=0,"ALTER TABLE portal_integration_audit ADD COLUMN outcome ENUM('allowed','denied','replayed','conflicted','failed') NULL AFTER action",'SELECT 1');PREPARE portal_complete_stmt FROM @portal_complete_sql;EXECUTE portal_complete_stmt;DEALLOCATE PREPARE portal_complete_stmt;
SET @portal_complete_sql=IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='portal_integration_audit' AND index_name='idx_portal_integration_audit_correlation')=0,'ALTER TABLE portal_integration_audit ADD INDEX idx_portal_integration_audit_correlation (correlation_id,created_at)','SELECT 1');PREPARE portal_complete_stmt FROM @portal_complete_sql;EXECUTE portal_complete_stmt;DEALLOCATE PREPARE portal_complete_stmt;

CREATE TABLE IF NOT EXISTS portal_projection_resource_state (
    integration_profile_id BIGINT UNSIGNED NOT NULL,
    workspace_public_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    route_type ENUM('portal','catalog') NOT NULL,
    resource_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    resource_public_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_version VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    record_json JSON NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (integration_profile_id,workspace_public_id,route_type,resource_type,resource_public_id),
    KEY idx_portal_projection_resource_workspace (workspace_public_id,route_type,resource_type),
    CONSTRAINT fk_portal_projection_resource_profile FOREIGN KEY (integration_profile_id) REFERENCES portal_integration_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_manager_scope_state (
    integration_profile_id BIGINT UNSIGNED NOT NULL,
    workspace_id BIGINT UNSIGNED NOT NULL,
    scope_type ENUM('workspace','organization','department','project') NOT NULL,
    scope_public_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('active','recovery_required') NOT NULL DEFAULT 'active',
    last_manager_removed_at DATETIME(6) NULL,
    updated_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (integration_profile_id,workspace_id,scope_type,scope_public_id),
    KEY idx_portal_manager_recovery (state,updated_at),
    CONSTRAINT fk_portal_manager_state_profile FOREIGN KEY (integration_profile_id) REFERENCES portal_integration_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_manager_state_workspace FOREIGN KEY (workspace_id) REFERENCES portal_v2_workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_manager_state_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @portal_complete_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='item_library' AND column_name='portal_pricing_typical_minimum')=0,'ALTER TABLE item_library ADD COLUMN portal_pricing_typical_minimum DECIMAL(12,2) NULL AFTER pricing_currency','SELECT 1');PREPARE portal_complete_stmt FROM @portal_complete_sql;EXECUTE portal_complete_stmt;DEALLOCATE PREPARE portal_complete_stmt;
SET @portal_complete_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='item_library' AND column_name='portal_pricing_typical_maximum')=0,'ALTER TABLE item_library ADD COLUMN portal_pricing_typical_maximum DECIMAL(12,2) NULL AFTER portal_pricing_typical_minimum','SELECT 1');PREPARE portal_complete_stmt FROM @portal_complete_sql;EXECUTE portal_complete_stmt;DEALLOCATE PREPARE portal_complete_stmt;
