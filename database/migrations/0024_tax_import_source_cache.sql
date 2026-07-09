SET @tax_rates_country_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'tax_rates'
      AND column_name = 'country'
);

SET @tax_rates_country_sql := IF(
    @tax_rates_country_exists = 0,
    'ALTER TABLE tax_rates ADD COLUMN country VARCHAR(100) NULL DEFAULT ''USA'' AFTER name',
    'SELECT 1'
);

PREPARE tax_rates_country_stmt FROM @tax_rates_country_sql;
EXECUTE tax_rates_country_stmt;
DEALLOCATE PREPARE tax_rates_country_stmt;

CREATE TABLE IF NOT EXISTS fips_counties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    state_abbr VARCHAR(2) NOT NULL,
    county_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_fips (state_fips, county_fips)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_jurisdictions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    jurisdiction_code VARCHAR(10) DEFAULT NULL,
    jurisdiction_type ENUM('state','county','city','special') NOT NULL DEFAULT 'county',
    state_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    county_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    city_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    special_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    total_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tax_jurisdiction_state (state_fips, county_fips),
    INDEX idx_tax_jurisdiction_code (state_fips, county_fips, jurisdiction_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_boundaries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zip5_start VARCHAR(5) NOT NULL,
    zip4_start VARCHAR(4) NOT NULL,
    zip5_end VARCHAR(5) NOT NULL,
    zip4_end VARCHAR(4) NOT NULL,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    jurisdiction_code VARCHAR(10) DEFAULT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tax_boundaries_state_zip (state_fips, zip5_start),
    INDEX idx_tax_boundaries_county (state_fips, county_fips),
    INDEX idx_tax_boundaries_jurisdiction (state_fips, county_fips, jurisdiction_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_boundaries_stage (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    batch_key VARCHAR(32) NOT NULL,
    zip5_start VARCHAR(5) NOT NULL,
    zip4_start VARCHAR(4) NOT NULL,
    zip5_end VARCHAR(5) NOT NULL,
    zip4_end VARCHAR(4) NOT NULL,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    jurisdiction_code VARCHAR(10) DEFAULT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tax_boundary_stage_batch (batch_key),
    INDEX idx_tax_boundary_stage_state_zip (state_fips, zip5_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_zip_complexity (
    zip5 VARCHAR(5) PRIMARY KEY,
    is_complex TINYINT(1) NOT NULL DEFAULT 0,
    reason VARCHAR(50) DEFAULT NULL,
    state_fips VARCHAR(2) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tax_zip_complexity_state (state_fips)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_import_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_fips VARCHAR(2) NOT NULL,
    file_type ENUM('fips','rates','boundaries') NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    content_hash CHAR(64) NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    state_tax_rate DECIMAL(8,4) NULL,
    imported_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tax_import_file (state_fips, file_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
