ALTER TABLE organizations
    MODIFY COLUMN link_strategy ENUM('department_links_only','overall_folder','shared_folder') NOT NULL DEFAULT 'overall_folder';
