-- database/migrations/005_contracts_quote_nullable.sql
-- Make contracts.quote_id nullable and set FK to ON DELETE SET NULL so standalone contracts are allowed

ALTER TABLE contracts DROP FOREIGN KEY fk_contracts_quote;
ALTER TABLE contracts MODIFY quote_id INT NULL;
ALTER TABLE contracts ADD CONSTRAINT fk_contracts_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL;