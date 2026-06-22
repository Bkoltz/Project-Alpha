-- database/migrations/016_expense_categories.sql
-- Expense categories with IRS Schedule C pre-seed

CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    parent_id INT NULL DEFAULT NULL,
    tax_deductible TINYINT(1) NOT NULL DEFAULT 1,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    color VARCHAR(7) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_exp_cat_org (organization_id),
    INDEX idx_exp_cat_parent (parent_id),
    CONSTRAINT fk_exp_cat_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_exp_cat_parent FOREIGN KEY (parent_id) REFERENCES expense_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pre-seed IRS Schedule C categories (system categories, not deletable)
INSERT INTO expense_categories (organization_id, name, is_system) VALUES
(1, 'Advertising', 1),
(1, 'Car & Truck Expenses', 1),
(1, 'Commissions & Fees', 1),
(1, 'Contract Labor', 1),
(1, 'Depletion', 1),
(1, 'Depreciation', 1),
(1, 'Employee Benefits', 1),
(1, 'Insurance', 1),
(1, 'Interest - Mortgage', 1),
(1, 'Interest - Other', 1),
(1, 'Legal & Professional Services', 1),
(1, 'Office Expense', 1),
(1, 'Pension & Profit-Sharing', 1),
(1, 'Rent - Equipment', 1),
(1, 'Rent - Vehicles/Machinery', 1),
(1, 'Rent - Other', 1),
(1, 'Repairs & Maintenance', 1),
(1, 'Supplies', 1),
(1, 'Taxes & Licenses', 1),
(1, 'Travel & Meals', 1),
(1, 'Utilities', 1),
(1, 'Wages', 1),
(1, 'Other', 1);