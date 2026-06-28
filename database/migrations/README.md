# Project Alpha Database Migrations

Project Alpha maintains two schema paths:

- `database/init.sql` is the complete current schema for a fresh database.
- Numbered SQL files in `database/migrations/` upgrade existing databases.

The `deprecated/` directory is retained only for historical reference and is not part of the active migration path.

## Startup Behavior

The web container entrypoint:

1. Waits for MySQL.
2. Loads `database/init.sql` when the primary document schema is missing.
3. Ensures the initial administrator and default organization membership exist.
4. Runs `src/migrations/run_migrations.php`.
5. Starts Apache even when some migration failures are reported, so operators must inspect logs.

The runner records successful files and checksums in `schema_migrations`. Failed migrations are not marked as applied and are retried on a later run.

## Creating a Migration

1. Choose the next unused numeric prefix.
2. Use a descriptive filename such as `033_add_invoice_delivery_state.sql`.
3. Make forward operations idempotent where MySQL permits.
4. Use nullable columns or safe defaults for existing rows.
5. Update `database/init.sql` to represent the same final schema.
6. Add or update tests and documentation.

Rollback scripts must end in `_rollback.sql`; automatic migration discovery excludes them.

## Validation

```bash
# List pending migrations without applying them
docker compose exec -T web \
  php /var/www/src/migrations/run_migrations.php --dry-run --verbose

# Apply pending migrations manually
docker compose exec -T web \
  php /var/www/src/migrations/run_migrations.php --verbose
```

Before applying pending files, the runner attempts a compressed MySQL backup in `/var/www/backups/pre-migration/`. Backup failure is logged but does not stop migration execution.

## Production Rules

- Test a fresh install and an upgrade from a representative staging backup.
- Verify the pre-migration backup can be restored.
- Do not modify live tables manually as a normal deployment step.
- Do not delete or rewrite a migration that has already shipped.
- Avoid destructive drop/rename changes in the same release that introduces replacements.
- Review the post-migration ACL health check and container logs.

Set `SKIP_MIGRATIONS_ON_BOOT=true` only as a temporary recovery measure while diagnosing a failed migration.
