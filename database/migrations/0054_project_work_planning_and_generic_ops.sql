-- Project-scoped work planning, division ownership, automatic external access,
-- and multi-worker task assignments.

SET @has_projects_business_unit := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='projects' AND column_name='business_unit_id');
SET @sql := IF(@has_projects_business_unit=0,'ALTER TABLE projects ADD COLUMN business_unit_id INT NULL AFTER department_id','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has_projects_business_unit_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='projects' AND index_name='idx_projects_business_unit');
SET @sql := IF(@has_projects_business_unit_index=0,'ALTER TABLE projects ADD INDEX idx_projects_business_unit (business_unit_id,status)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has_projects_business_unit_fk := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema=DATABASE() AND table_name='projects' AND constraint_name='fk_projects_business_unit' AND constraint_type='FOREIGN KEY');
SET @sql := IF(@has_projects_business_unit_fk=0,'ALTER TABLE projects ADD CONSTRAINT fk_projects_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE projects
SET business_unit_id=(SELECT MIN(id) FROM business_units WHERE is_active=1)
WHERE business_unit_id IS NULL
  AND (SELECT COUNT(*) FROM business_units WHERE is_active=1)=1;

INSERT INTO app_config (organization_id,config_key,config_value)
SELECT 0,'default_business_unit_id',CAST(MIN(id) AS CHAR)
FROM business_units
WHERE is_active=1
HAVING COUNT(*)=1
ON DUPLICATE KEY UPDATE config_value=config_value;

SET @has_entitlement_manual := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='application_entitlements' AND column_name='manual_enabled');
SET @sql := IF(@has_entitlement_manual=0,'ALTER TABLE application_entitlements ADD COLUMN manual_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER enabled','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has_entitlement_automatic := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='application_entitlements' AND column_name='automatic_enabled');
SET @sql := IF(@has_entitlement_automatic=0,'ALTER TABLE application_entitlements ADD COLUMN automatic_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER manual_enabled','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has_entitlement_oversight := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='application_entitlements' AND column_name='oversight_enabled');
SET @sql := IF(@has_entitlement_oversight=0,'ALTER TABLE application_entitlements ADD COLUMN oversight_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER automatic_enabled','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has_entitlement_effective_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='application_entitlements' AND index_name='idx_application_entitlement_effective');
SET @sql := IF(@has_entitlement_effective_index=0,'ALTER TABLE application_entitlements ADD INDEX idx_application_entitlement_effective (application_key,enabled,manual_enabled,automatic_enabled,user_id)','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Existing explicit grants remain explicit grants. Oversight remains off until
-- an administrator deliberately selects it in the new exception workflow.
UPDATE application_entitlements SET manual_enabled=enabled;

CREATE TABLE IF NOT EXISTS application_entitlement_oversight_units (
    entitlement_id BIGINT UNSIGNED NOT NULL,
    business_unit_id INT NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (entitlement_id,business_unit_id),
    INDEX idx_entitlement_oversight_unit (business_unit_id,entitlement_id),
    CONSTRAINT fk_entitlement_oversight_entitlement FOREIGN KEY (entitlement_id) REFERENCES application_entitlements(id) ON DELETE CASCADE,
    CONSTRAINT fk_entitlement_oversight_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO application_entitlement_oversight_units (entitlement_id,business_unit_id)
SELECT entitlement_id,business_unit_id FROM application_entitlement_business_units
ON DUPLICATE KEY UPDATE business_unit_id=VALUES(business_unit_id);

CREATE TABLE IF NOT EXISTS task_assignments (
    task_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    assigned_by INT NULL,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (task_id,user_id),
    INDEX idx_task_assignment_user (user_id,task_id),
    CONSTRAINT fk_task_assignment_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_assignment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_assignment_actor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO task_assignments (task_id,user_id,assigned_by)
SELECT id,assignee_user_id,created_by FROM tasks WHERE assignee_user_id IS NOT NULL
ON DUPLICATE KEY UPDATE assigned_by=VALUES(assigned_by);

UPDATE operations o
JOIN projects p ON p.id=o.project_id
SET o.business_unit_id=p.business_unit_id
WHERE p.business_unit_id IS NOT NULL;

UPDATE tasks t
JOIN projects p ON p.id=t.project_id
SET t.business_unit_id=p.business_unit_id
WHERE p.business_unit_id IS NOT NULL;
