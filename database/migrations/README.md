# Project Alpha Database Migrations

Project Alpha 0.5.0 starts from the immutable `database/baseline.sql` schema. The baseline inserts version `0` into `schema_migrations`; this directory contains only later, forward-only changes.

For `0.5.0-rc1`, there are no post-baseline SQL migrations. `php src/migrations/run_migrations.php --validate-files` should report `0` migration files until the first schema change after the 0.5.0 baseline is frozen.

## File Contract

- Name files `0001_description.sql`, `0002_description.sql`, and so on.
- Versions must begin at `0001` and remain contiguous and unique.
- Never edit or remove a migration after it ships. Stored SHA-256 checksums are enforced.
- Rollback files, `DELIMITER` blocks, gaps, malformed names, and empty files are rejected.
- Do not copy historical pre-0.5.0 SQL back into this directory.
- Before `0.5.0` ships, fold schema work into `database/baseline.sql`; after it ships, add the next immutable migration instead.

## Execution Contract

The one-shot Compose `migrate` service:

1. Refuses to modify a non-empty database without the 0.5.0 baseline marker.
2. Loads `database/baseline.sql` only into an empty database.
3. Validates the migration sequence and applied checksums. An empty post-baseline migration directory is valid.
4. Requires a successful compressed backup before applying pending post-baseline migrations.
5. Applies pending migrations, if any, and validates critical schema invariants.
6. Reconciles the Compose administrator password without logging it or its hash.

Web and cron depend on successful completion of this service.

## Validation

```bash
php src/migrations/run_migrations.php --validate-files

docker compose run --rm migrate \
  php /var/www/src/migrations/run_migrations.php --dry-run --verbose
```

Fix forward after a migration ships. MySQL DDL can auto-commit, so a failed change may require restoring the required pre-migration backup before deploying a corrected migration.
