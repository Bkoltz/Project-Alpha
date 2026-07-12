-- Make workforce profile ownership and synchronization state explicit without
-- coupling PA or AlphaLedger authentication credentials.

SET @tm_profile_source_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='team_members' AND column_name='profile_source');
SET @sql := IF(@tm_profile_source_exists=0, 'ALTER TABLE team_members ADD COLUMN profile_source ENUM(''pa'',''alphaledger'') NOT NULL DEFAULT ''pa'' AFTER is_active', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tm_last_synced_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='team_members' AND column_name='last_synced_at');
SET @sql := IF(@tm_last_synced_exists=0, 'ALTER TABLE team_members ADD COLUMN last_synced_at DATETIME NULL AFTER profile_source', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO team_members (user_id,display_name,email,is_active,profile_source)
SELECT u.id,COALESCE(NULLIF(u.username,''),u.email),u.email,
       IF(u.is_disabled=0 AND u.deleted_at IS NULL,1,0),'pa'
FROM users u
LEFT JOIN team_members tm ON tm.user_id=u.id
WHERE tm.id IS NULL;

UPDATE team_members tm
JOIN alphaledger_employee_mappings m ON m.team_member_id=tm.id
SET tm.profile_source='alphaledger',
    tm.last_synced_at=COALESCE(tm.last_synced_at,m.confirmed_at);

CREATE TABLE IF NOT EXISTS alphaledger_team_assignments (
    project_id INT NOT NULL,
    team_member_id BIGINT UNSIGNED NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id,team_member_id),
    INDEX idx_al_team_assignment_member (team_member_id,project_id),
    CONSTRAINT fk_al_team_assignment_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_team_assignment_member FOREIGN KEY (team_member_id) REFERENCES team_members(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_team_assignment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO alphaledger_team_assignments (project_id,team_member_id,created_by,created_at)
SELECT a.project_id,tm.id,a.created_by,a.created_at
FROM alphaledger_project_assignments a
JOIN team_members tm ON tm.user_id=a.user_id;

-- PA no longer provisions or deactivates AL people. Retire undelivered legacy
-- PA-person events without emitting a destructive AL employee change.
UPDATE alphaledger_events
SET delivery_state='delivered',
    delivered_at=COALESCE(delivered_at,UTC_TIMESTAMP()),
    last_error='Retired: AlphaLedger owns workforce identities.'
WHERE event_type IN ('person.upserted','person.deactivated')
  AND delivery_state<>'delivered';

UPDATE alphaledger_object_state
SET is_present=0
WHERE object_type='person' AND is_present=1;
