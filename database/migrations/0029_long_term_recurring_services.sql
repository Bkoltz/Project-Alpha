-- Effective-dated recurring services let one long-term agreement carry
-- independently scheduled charges (for example annual hosting plus monthly
-- advertising management) without rewriting historical invoices.
CREATE TABLE IF NOT EXISTS contract_recurring_services (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    billing_interval_count INT NOT NULL DEFAULT 1,
    billing_interval_unit ENUM('day','week','month','year') NOT NULL DEFAULT 'month',
    effective_from DATE NOT NULL,
    effective_until DATE NULL,
    next_invoice_date DATE NULL,
    last_invoice_date DATE NULL,
    status ENUM('pending','active','paused','ended') NOT NULL DEFAULT 'pending',
    approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    is_base TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_crs_contract (contract_id),
    INDEX idx_crs_due (status, approval_status, next_invoice_date),
    INDEX idx_crs_effective (effective_from, effective_until),
    CONSTRAINT fk_crs_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_crs_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_amendments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    recurring_service_id BIGINT NULL,
    amendment_type ENUM('service_added','service_updated','service_approved','service_paused','service_resumed','service_ended','proration') NOT NULL,
    approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    effective_date DATE NOT NULL,
    summary VARCHAR(500) NOT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    signed_document_path VARCHAR(500) NULL,
    approved_at TIMESTAMP NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ca_contract (contract_id, created_at),
    INDEX idx_ca_service (recurring_service_id),
    CONSTRAINT fk_ca_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_ca_service FOREIGN KEY (recurring_service_id) REFERENCES contract_recurring_services(id) ON DELETE SET NULL,
    CONSTRAINT fk_ca_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Materialize the existing per-invoice terms as the base service. This keeps
-- every existing monthly/yearly contract on its current schedule.
INSERT INTO contract_recurring_services (
    contract_id, name, description, amount, billing_interval_count,
    billing_interval_unit, effective_from, effective_until, next_invoice_date,
    last_invoice_date, status, approval_status, is_base, created_by
)
SELECT
    c.id,
    LEFT(COALESCE(NULLIF(c.scope, ''), 'Recurring service'), 190),
    c.scope,
    COALESCE(c.price_per_invoice, 0),
    GREATEST(c.billing_interval_count, 1),
    c.billing_interval_unit,
    COALESCE(c.start_date, DATE(c.created_at)),
    c.end_date,
    c.next_invoice_date,
    c.last_invoice_date,
    CASE
        WHEN c.status = 'active' THEN 'active'
        WHEN c.status = 'paused' THEN 'paused'
        WHEN c.status IN ('completed','cancelled','denied','void') THEN 'ended'
        ELSE 'pending'
    END,
    'approved',
    1,
    c.created_by
FROM contracts c
WHERE c.contract_type = 'long_term'
  AND c.pricing_type = 'per_invoice'
  AND NOT EXISTS (
      SELECT 1 FROM contract_recurring_services s
      WHERE s.contract_id = c.id AND s.is_base = 1
  );
