# Project Alpha - Database Migrations (DEPRECATED)

## ⚠️ DEPRECATED - DO NOT MODIFY

This directory contains **legacy migration files** that are kept for historical reference only.

## Current Source of Truth

**`database/init.sql`** is now the single source of truth for the database schema.

## Why the Change?

The migration-based approach was replaced with a single initialization file to:
- Keep the schema clean and maintainable
- Avoid accumulating legacy column definitions
- Make it easier to see the full schema at once
- Reduce bugs from partial migrations

## Docker Build

The `docker/start.sh` script automatically runs `database/init.sql` when the database is empty (fresh volume). It does **not** run files from this directory.

## Historical Files

These files are no longer executed but remain for reference:
- `000_all_DEPRECATED.sql` - Old consolidated schema
- `001_init.sql` through `014_legacy_tables.sql` - Individual migration steps

## If You Need to Make Schema Changes

1. Edit **`database/init.sql`** directly
2. Rebuild the Docker containers (data will be reset on fresh volume)
3. For production, you would need a proper migration strategy
