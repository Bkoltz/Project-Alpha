# Migration Safety

Project Alpha initializes fresh databases from `database/init.sql` and upgrades existing databases with tracked files in `database/migrations/`.

## Choose the Correct Path

- **Fresh disposable environment:** recreate volumes and verify `init.sql` produces the current schema.
- **Environment with data:** keep the database, run pending migrations, and verify a backup first.

Never destroy a production volume to apply a schema update.

## Before Deployment

1. Add an idempotent forward migration.
2. Update `database/init.sql` to the same final state.
3. Use safe defaults or nullable columns for existing rows.
4. Run the migration dry run.
5. Test an upgrade using a representative staging backup.
6. Verify application and ACL health checks.

```bash
docker compose exec -T web \
  php /var/www/src/migrations/run_migrations.php --dry-run --verbose

docker compose exec -T web \
  php /var/www/src/migrations/run_migrations.php --verbose
```

The runner attempts a compressed pre-migration backup under `/var/www/backups/pre-migration/`. Backup failure is non-fatal, so read the output rather than assuming success.

## Failure Recovery

- A failed migration is not recorded as applied and is retried later.
- MySQL DDL may auto-commit; do not assume transactional rollback.
- `SKIP_MIGRATIONS_ON_BOOT=true` is an emergency startup valve, not a permanent setting.
- Fix forward when a migration has shipped; do not silently rewrite its history.
- Restore to a separate environment before replacing the affected database.

See [Database Migrations](../database/migrations/README.md).
