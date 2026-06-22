-- database/migrations/019_mileage_logs.sql
-- Mileage tracking for vehicle expense deduction (IRS standard mileage rate)

CREATE TABLE IF NOT EXISTS mileage_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    user_id INT NULL DEFAULT NULL,
    client_id INT NULL DEFAULT NULL,
    project_id INT NULL DEFAULT NULL,
    trip_date DATE NOT NULL,
    start_location VARCHAR(255) NULL DEFAULT NULL,
    end_location VARCHAR(255) NULL DEFAULT NULL,
    miles DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    purpose ENUM('business','medical','moving','charitable','personal') NOT NULL DEFAULT 'business',
    description TEXT NULL,
    round_trip TINYINT(1) NOT NULL DEFAULT 0,
    mileage_rate DECIMAL(5,3) NOT NULL DEFAULT 0.670,
    is_billable TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mileage_org (organization_id),
    INDEX idx_mileage_date (trip_date),
    INDEX idx_mileage_client (client_id),
    INDEX idx_mileage_purpose (purpose),
    CONSTRAINT fk_mileage_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_mileage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_mileage_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;