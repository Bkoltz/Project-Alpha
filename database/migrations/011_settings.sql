-- ============================================================================
-- SETTINGS
-- ============================================================================

  IF NOT EXISTS tax_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    country VARCHAR(100) NOT NULL DEFAULT 'USA',
    state VARCHAR(100) NULL,
    county VARCHAR(100) NULL,
    rate DECIMAL(5, 2) NOT NULL DEFAULT 0,
    is_active TINYINT (1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tax_country (country),
    INDEX idx_tax_state (state),
    INDEX idx_tax_county (county)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
