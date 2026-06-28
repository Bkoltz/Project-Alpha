-- Migration 033: Project monthly aggregate invoices
-- Adds project-level billing envelopes that group existing invoices without
-- counting aggregate totals as additional revenue.

USE project_alpha;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'client_id'
);
SET @sql := IF(@has_col = 1,
    'ALTER TABLE projects MODIFY client_id INT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS project_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    client_id INT NOT NULL,
    is_primary_billing TINYINT(1) NOT NULL DEFAULT 0,
    send_project_invoices TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_client (project_id, client_id),
    INDEX idx_project_clients_project (project_id),
    INDEX idx_project_clients_client (client_id),
    CONSTRAINT fk_project_clients_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_clients_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO project_clients (project_id, client_id, is_primary_billing, sort_order)
SELECT id, client_id, 1, 0
FROM projects
WHERE client_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS project_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    organization_id INT NULL,
    primary_client_id INT NULL,
    doc_number INT NULL,
    status ENUM('draft','sent','unpaid','partial','paid','void') NOT NULL DEFAULT 'unpaid',
    billing_period_start DATE NOT NULL,
    billing_period_end DATE NOT NULL,
    due_date DATE NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance_due DECIMAL(12,2) NOT NULL DEFAULT 0,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    paid_at TIMESTAMP NULL DEFAULT NULL,
    generated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_invoice_period (project_id, billing_period_start, billing_period_end),
    INDEX idx_project_invoices_project (project_id),
    INDEX idx_project_invoices_status (status),
    INDEX idx_project_invoices_due (due_date),
    CONSTRAINT fk_project_invoices_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_invoices_primary_client FOREIGN KEY (primary_client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_invoice_id INT NOT NULL,
    invoice_id INT NOT NULL,
    invoice_doc_number INT NULL,
    invoice_date DATE NULL,
    invoice_due_date DATE NULL,
    invoice_status VARCHAR(50) NULL,
    invoice_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_paid_at_generation DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_due_at_generation DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_applied DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_invoice_child_invoice (invoice_id),
    INDEX idx_project_invoice_items_parent (project_invoice_id),
    INDEX idx_project_invoice_items_invoice (invoice_id),
    CONSTRAINT fk_project_invoice_items_parent FOREIGN KEY (project_invoice_id) REFERENCES project_invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_invoice_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_invoice_id INT NOT NULL,
    notification_type VARCHAR(50) NOT NULL DEFAULT 'on_generate',
    email_to VARCHAR(255) NOT NULL,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_invoice_notification (project_invoice_id, notification_type, email_to),
    INDEX idx_project_invoice_notif_parent (project_invoice_id),
    CONSTRAINT fk_project_invoice_notif_parent FOREIGN KEY (project_invoice_id) REFERENCES project_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
