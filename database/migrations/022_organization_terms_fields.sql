-- Migration 022: Add optional per-organization terms columns.
-- All nullable = backward compatible. NULL falls back to global app_config terms.
ALTER TABLE organizations
    ADD COLUMN brand_terms TEXT NULL AFTER brand_postal,
    ADD COLUMN brand_long_term_terms TEXT NULL AFTER brand_terms,
    ADD COLUMN brand_on_demand_terms TEXT NULL AFTER brand_long_term_terms;
