-- User-centric Business Unit membership, explicit external access, and one
-- primary Project Manager. Keep prior applied migrations immutable.

CREATE TABLE IF NOT EXISTS business_unit_memberships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_unit_id INT NOT NULL,
    user_id INT NOT NULL,
    membership_role ENUM('member','head') NOT NULL DEFAULT 'member',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    assigned_by INT NULL,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ended_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_business_unit_membership_pair (business_unit_id,user_id,ended_at),
    INDEX idx_business_unit_membership_user (user_id,ended_at,is_primary),
    INDEX idx_business_unit_membership_unit (business_unit_id,ended_at,membership_role),
    CONSTRAINT fk_business_unit_membership_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE CASCADE,
    CONSTRAINT fk_business_unit_membership_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_business_unit_membership_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO business_unit_memberships
    (business_unit_id,user_id,membership_role,is_primary,assigned_by,assigned_at,ended_at)
SELECT wbu.business_unit_id,wp.user_id,
       CASE WHEN wbu.is_lead=1 THEN 'head' ELSE 'member' END,
       0,wbu.assigned_by,wbu.assigned_at,wbu.ends_at
FROM worker_business_units wbu
JOIN worker_profiles wp ON wp.id=wbu.worker_profile_id
WHERE wp.user_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM business_unit_memberships existing
      WHERE existing.business_unit_id=wbu.business_unit_id
        AND existing.user_id=wp.user_id
        AND existing.assigned_at=wbu.assigned_at
  );

-- Give a user with exactly one active membership an unambiguous primary unit.
UPDATE business_unit_memberships bum
JOIN (
    SELECT user_id,MIN(id) membership_id
    FROM business_unit_memberships
    WHERE ended_at IS NULL OR ended_at>UTC_TIMESTAMP(6)
    GROUP BY user_id
    HAVING COUNT(*)=1
) one_unit ON one_unit.membership_id=bum.id
SET bum.is_primary=1;

SET @has_project_manager := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='projects' AND column_name='manager_user_id');
SET @sql := IF(@has_project_manager=0,'ALTER TABLE projects ADD COLUMN manager_user_id INT NULL AFTER business_unit_id','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_project_manager_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='projects' AND index_name='idx_projects_manager');
SET @sql := IF(@has_project_manager_index=0,'ALTER TABLE projects ADD INDEX idx_projects_manager (manager_user_id,status)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_project_manager_fk := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema=DATABASE() AND table_name='projects' AND constraint_name='fk_projects_manager' AND constraint_type='FOREIGN KEY');
SET @sql := IF(@has_project_manager_fk=0,'ALTER TABLE projects ADD CONSTRAINT fk_projects_manager FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Only infer a manager when the historical Project Team is unambiguous.
UPDATE projects p
JOIN (
    SELECT project_id,MIN(user_id) user_id
    FROM project_assignments
    WHERE ends_at IS NULL OR ends_at>UTC_TIMESTAMP(6)
    GROUP BY project_id
    HAVING COUNT(*)=1
) one_member ON one_member.project_id=p.id
SET p.manager_user_id=one_member.user_id
WHERE p.manager_user_id IS NULL;

-- External access is now an explicit administrator-selected allowlist.
SET @external_ops_app_key := (SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='external_ops_application_key' LIMIT 1);
UPDATE application_entitlements entitlement
JOIN users account ON account.id=entitlement.user_id
LEFT JOIN worker_profiles worker ON worker.user_id=account.id
SET entitlement.enabled=CASE
        WHEN entitlement.manual_enabled=1
         AND account.is_disabled=0
         AND account.deleted_at IS NULL
         AND (worker.id IS NULL OR worker.status='active') THEN 1 ELSE 0 END,
    entitlement.automatic_enabled=0,
    entitlement.oversight_enabled=0
WHERE entitlement.application_key=@external_ops_app_key;

DELETE scoped FROM application_entitlement_business_units scoped
JOIN application_entitlements entitlement ON entitlement.id=scoped.entitlement_id
WHERE entitlement.application_key=@external_ops_app_key;
DELETE scoped FROM application_entitlement_oversight_units scoped
JOIN application_entitlements entitlement ON entitlement.id=scoped.entitlement_id
WHERE entitlement.application_key=@external_ops_app_key;
