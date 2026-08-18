-- Migration 0069: decouple project-invoice delivery recipients from project
-- membership and support explicit manual email recipients.

CREATE TABLE IF NOT EXISTS project_invoice_recipients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    client_id INT NULL,
    organization_id INT NULL,
    manual_email VARCHAR(254) NULL,
    manual_name VARCHAR(190) NULL,
    recipient_key VARCHAR(300) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_invoice_recipient (project_id, recipient_key),
    INDEX idx_project_invoice_recipients_project (project_id, sort_order),
    INDEX idx_project_invoice_recipients_client (client_id),
    INDEX idx_project_invoice_recipients_organization (organization_id),
    CONSTRAINT fk_project_invoice_recipients_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_invoice_recipients_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_invoice_recipients_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT chk_project_invoice_recipient_target CHECK (
        (client_id IS NOT NULL AND organization_id IS NULL AND manual_email IS NULL)
        OR (client_id IS NULL AND organization_id IS NOT NULL AND manual_email IS NULL)
        OR (client_id IS NULL AND organization_id IS NULL AND manual_email IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve the effective delivery list from the legacy project-membership
-- flags. Projects with no enabled recipients remain intentionally empty.
INSERT IGNORE INTO project_invoice_recipients
    (project_id, client_id, recipient_key, sort_order)
SELECT pc.project_id,
       pc.client_id,
       CONCAT('client:', pc.client_id),
       pc.sort_order
FROM project_clients pc
WHERE pc.send_project_invoices = 1;
