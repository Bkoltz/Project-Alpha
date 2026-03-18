-- Tax Import Tables Migration
-- Run this SQL to create tables for the expanded tax import feature

-- 1. FIPS Counties lookup table
CREATE TABLE IF NOT EXISTS fips_counties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    state_abbr VARCHAR(2) NOT NULL,
    county_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_fips (state_fips, county_fips),
    INDEX idx_state (state_fips),
    INDEX idx_state_abbr (state_abbr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Tax Jurisdictions table (stores all imported tax rates)
CREATE TABLE IF NOT EXISTS tax_jurisdictions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    jurisdiction_code VARCHAR(10) DEFAULT NULL,
    jurisdiction_type ENUM('state', 'county', 'city', 'special') NOT NULL DEFAULT 'county',
    state_rate DECIMAL(8,6) NOT NULL DEFAULT 0,
    county_rate DECIMAL(8,6) NOT NULL DEFAULT 0,
    city_rate DECIMAL(8,6) NOT NULL DEFAULT 0,
    special_rate DECIMAL(8,6) NOT NULL DEFAULT 0,
    total_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_jurisdiction (state_fips, county_fips, jurisdiction_code, start_date),
    INDEX idx_state (state_fips),
    INDEX idx_county (state_fips, county_fips),
    INDEX idx_jurisdiction (jurisdiction_code),
    INDEX idx_active (is_active),
    INDEX idx_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Tax Boundaries table (ZIP+4 to jurisdiction mappings)
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
    INDEX idx_zip5 (zip5_start),
    INDEX idx_zip_range (zip5_start, zip4_start, zip5_end, zip4_end),
    INDEX idx_state_county (state_fips, county_fips),
    INDEX idx_jurisdiction (jurisdiction_code),
    INDEX idx_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Tax ZIP Complexity table (flags which ZIP5s need ZIP+4 lookup)
CREATE TABLE IF NOT EXISTS tax_zip_complexity (
    zip5 VARCHAR(5) PRIMARY KEY,
    is_complex TINYINT(1) NOT NULL DEFAULT 0,
    reason VARCHAR(50) DEFAULT NULL,
    state_fips VARCHAR(2) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_complex (is_complex),
    INDEX idx_state (state_fips)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Add state_fips to existing tax_rates table if not exists (for linking)
-- ALTER TABLE tax_rates ADD COLUMN state_fips VARCHAR(2) DEFAULT NULL AFTER county;
-- ALTER TABLE tax_rates ADD COLUMN county_fips VARCHAR(3) DEFAULT NULL AFTER state_fips;
-- ALTER TABLE tax_rates ADD COLUMN jurisdiction_code VARCHAR(10) DEFAULT NULL AFTER county_fips;
