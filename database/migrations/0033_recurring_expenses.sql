-- Recurring expense templates generate immutable normal expense rows on each
-- scheduled date. The unique occurrence key makes retries idempotent.

CREATE TABLE IF NOT EXISTS recurring_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL DEFAULT NULL,
    vendor_id INT NULL DEFAULT NULL,
    category_id INT NULL DEFAULT NULL,
    client_id INT NULL DEFAULT NULL,
    project_id INT NULL DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    description VARCHAR(500) NOT NULL,
    interval_count INT NOT NULL DEFAULT 1,
    interval_unit ENUM('week','month','year') NOT NULL DEFAULT 'month',
    start_date DATE NOT NULL,
    next_expense_date DATE NULL,
    end_date DATE NULL,
    last_generated_date DATE NULL,
    generated_count INT NOT NULL DEFAULT 0,
    is_billable TINYINT(1) NOT NULL DEFAULT 0,
    is_tax_deductible TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active','paused','ended') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_by INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rec_exp_org (organization_id),
    INDEX idx_rec_exp_vendor (vendor_id),
    INDEX idx_rec_exp_category (category_id),
    INDEX idx_rec_exp_client (client_id),
    INDEX idx_rec_exp_project (project_id),
    INDEX idx_rec_exp_due (status, next_expense_date),
    CONSTRAINT fk_rec_exp_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_exp_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_exp_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_exp_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_exp_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_exp_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @recurring_expense_id_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='expenses' AND column_name='recurring_expense_id'
);
SET @sql := IF(@recurring_expense_id_exists=0,
    'ALTER TABLE expenses ADD COLUMN recurring_expense_id INT NULL DEFAULT NULL AFTER receipt_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @recurring_occurrence_date_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='expenses' AND column_name='recurring_occurrence_date'
);
SET @sql := IF(@recurring_occurrence_date_exists=0,
    'ALTER TABLE expenses ADD COLUMN recurring_occurrence_date DATE NULL DEFAULT NULL AFTER recurring_expense_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @recurring_expense_index_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='expenses' AND index_name='idx_exp_recurring'
);
SET @sql := IF(@recurring_expense_index_exists=0,
    'ALTER TABLE expenses ADD INDEX idx_exp_recurring (recurring_expense_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @recurring_occurrence_unique_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='expenses' AND index_name='uq_exp_recurring_occurrence'
);
SET @sql := IF(@recurring_occurrence_unique_exists=0,
    'ALTER TABLE expenses ADD UNIQUE INDEX uq_exp_recurring_occurrence (recurring_expense_id, recurring_occurrence_date)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @recurring_expense_fk_exists := (
    SELECT COUNT(*) FROM information_schema.referential_constraints
    WHERE constraint_schema=DATABASE() AND table_name='expenses' AND constraint_name='fk_exp_recurring'
);
SET @sql := IF(@recurring_expense_fk_exists=0,
    'ALTER TABLE expenses ADD CONSTRAINT fk_exp_recurring FOREIGN KEY (recurring_expense_id) REFERENCES recurring_expenses(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
