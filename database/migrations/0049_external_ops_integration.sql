-- Optional external operations projection and entitlement synchronization.
-- Optional external operations integration. The application key is configured
-- per deployment.
-- the column remains explicit so webhook and snapshot records are self-describing.

CREATE TABLE IF NOT EXISTS application_entitlements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    application_key VARCHAR(64) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    role_key VARCHAR(64) NOT NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_application_entitlement (user_id, application_key),
    INDEX idx_application_entitlement_app (application_key, enabled, user_id),
    CONSTRAINT fk_application_entitlement_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_application_entitlement_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_application_entitlement_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_application_entitlement_role CHECK (role_key IN ('role-admin','role-operator'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_entitlement_business_units (
    entitlement_id BIGINT UNSIGNED NOT NULL,
    business_unit_id INT NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (entitlement_id, business_unit_id),
    INDEX idx_entitlement_business_unit (business_unit_id, entitlement_id),
    CONSTRAINT fk_entitlement_scope_entitlement FOREIGN KEY (entitlement_id) REFERENCES application_entitlements(id) ON DELETE CASCADE,
    CONSTRAINT fk_entitlement_scope_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integration_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id CHAR(36) NOT NULL,
    integration_key VARCHAR(64) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    payload_json JSON NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NOT NULL,
    delivered_at DATETIME(6) NULL,
    last_error TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_integration_outbox_event (event_id),
    INDEX idx_integration_outbox_due (integration_key, delivered_at, next_attempt_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    business_unit_id INT NULL,
    title VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    scheduled_start_at DATETIME(6) NULL,
    scheduled_end_at DATETIME(6) NULL,
    location VARCHAR(500) NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_operations_project (project_id, status, scheduled_start_at),
    INDEX idx_operations_business_unit (business_unit_id, status, scheduled_start_at),
    CONSTRAINT fk_operations_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_operations_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_operations_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_operations_status CHECK (status IN ('draft','scheduled','in_progress','completed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operation_assignments (
    operation_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    assignment_role VARCHAR(100) NULL,
    assigned_by INT NULL,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (operation_id, user_id),
    INDEX idx_operation_assignment_user (user_id, operation_id),
    CONSTRAINT fk_operation_assignment_operation FOREIGN KEY (operation_id) REFERENCES operations(id) ON DELETE CASCADE,
    CONSTRAINT fk_operation_assignment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_operation_assignment_actor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operation_id BIGINT UNSIGNED NULL,
    project_id INT NOT NULL,
    business_unit_id INT NULL,
    assignee_user_id INT NULL,
    title VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'todo',
    due_at DATETIME(6) NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_tasks_project (project_id, status, due_at),
    INDEX idx_tasks_operation (operation_id, status, due_at),
    INDEX idx_tasks_assignee (assignee_user_id, status, due_at),
    INDEX idx_tasks_business_unit (business_unit_id, status, due_at),
    CONSTRAINT fk_tasks_operation FOREIGN KEY (operation_id) REFERENCES operations(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_assignee FOREIGN KEY (assignee_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_tasks_status CHECK (status IN ('todo','in_progress','blocked','completed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
