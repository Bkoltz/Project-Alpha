-- database/migrations/003_force_password_reset.sql
-- Add force_password_reset column to users table

ALTER TABLE users ADD COLUMN force_password_reset TINYINT(1) NOT NULL DEFAULT 0;