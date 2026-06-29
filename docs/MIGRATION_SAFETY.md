# Migration Safety

Project Alpha 0.5.0 initializes empty databases from `database/baseline.sql`. It does not recognize or upgrade pre-0.5.0 databases.

Future schema changes are immutable files in `database/migrations/`. The runner enforces contiguous four-digit versions, filenames, checksums, backup success, and post-migration schema health. Any failure exits nonzero and prevents web and cron startup.

## Before a Future Deployment

1. Add the next sequential forward migration.
2. Use safe defaults or nullable columns for existing rows.
3. Run PHPUnit against MySQL.
4. Run the migration dry run.
5. Test the upgrade using staging data.
6. Restore the generated backup into a separate environment.

```bash
docker compose run --rm migrate \
  php /var/www/src/migrations/run_migrations.php --dry-run --verbose
```

Never rewrite an applied migration or bypass a failed migrator by manually starting web or cron. Restore, correct the forward migration, and validate again.

For the breaking 0.5.0 transition, follow [0.5.0 Database Reset](0.5.0-database-reset.md).
