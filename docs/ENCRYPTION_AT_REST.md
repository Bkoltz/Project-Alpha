# Encryption at Rest — InnoDB Tablespace Encryption

Status: APPLIED June 2026 (MySQL 8.4, component_keyring_file).

## Current setup
- `database/mysql/mysqld.my` — manifest, bind-mounted to `/usr/sbin/mysqld.my`
- `database/mysql/component_keyring_file.cnf` — bind-mounted to
  `/usr/lib64/mysql/plugin/component_keyring_file.cnf`
- Keyring data lives in the `db_keyring` named volume
  (`/var/lib/mysql-keyring/component_keyring_file`)
- `default_table_encryption=ON`, `innodb-redo-log-encrypt=ON`,
  `innodb-undo-log-encrypt=ON` in the db service command
- Schema default: `ALTER SCHEMA project_alpha DEFAULT ENCRYPTION='Y'`
- All 61 existing tablespaces altered to `ENCRYPTION='Y'`

## Verify
```sql
SELECT * FROM performance_schema.keyring_component_status;     -- Active
SELECT COUNT(*) FROM information_schema.innodb_tablespaces
WHERE name LIKE 'project_alpha/%' AND encryption='Y';          -- = table count
```

## Operational gotchas (learned the hard way)
- MySQL 8.4 removed the legacy `keyring_file.so` plugin
  (`--early-plugin-load` does NOT work). Use the component + manifest.
- The bind-mounted manifest/config files must be world-readable (644) —
  mysqld runs as the `mysql` user.
- The keyring file must exist and be owned `mysql:mysql` BEFORE first
  start. The entrypoint creates it root-owned otherwise and mysqld
  crash-loops with "Failed to read keyring file". Fix:
  `docker run --rm -v project-alpha_db_keyring:/k mysql:8 sh -c \
   "touch /k/component_keyring_file && chown -R mysql:mysql /k"`
- **BACK UP THE `db_keyring` VOLUME.** Without it, the data files are
  unrecoverable. Note `tools/db_backup.sh` mysqldump output is plaintext
  SQL (that's the point — restorable anywhere); store dumps securely.
- This protects against stolen disks/volumes, NOT against root on the
  host. Revisit a KMS-backed keyring at launch scale.

## What this covers
Encrypting the MySQL data files on disk so a stolen disk/volume does not
expose client data, invoices, or payment records. This complements (does
not replace) the application-level AES-256-GCM encryption of secrets.

## Steps (MySQL 8, docker compose)

1. Add the keyring component and encryption defaults to the db service in
   docker-compose.yml:

   ```yaml
   db:
     command: >
       --log-error-verbosity=1
       --early-plugin-load=keyring_file.so
       --keyring_file_data=/var/lib/mysql-keyring/keyring
       --default-table-encryption=ON
       --innodb-redo-log-encrypt=ON
       --innodb-undo-log-encrypt=ON
     volumes:
       - db_keyring:/var/lib/mysql-keyring   # add named volume
   ```

2. Recreate the db container during a maintenance window:
   `docker compose up -d db`

3. Encrypt EXISTING tablespaces (new tables are covered by
   default-table-encryption=ON, existing ones are not):

   ```sql
   -- generate the statements:
   SELECT CONCAT('ALTER TABLE `', table_schema, '`.`', table_name, '` ENCRYPTION=''Y'';')
   FROM information_schema.tables
   WHERE table_schema = 'project_alpha' AND table_type = 'BASE TABLE';
   -- then run the output. Each ALTER rewrites the tablespace (locks the table).
   ```

4. Verify:
   ```sql
   SELECT table_schema, table_name, create_options
   FROM information_schema.tables
   WHERE table_schema='project_alpha' AND create_options LIKE '%ENCRYPTION%';
   ```

## Caveats
- The keyring FILE plugin stores the master key on the same host — this
  protects against stolen disks/volumes, NOT against root on the host.
  Good enough for current scale; revisit a KMS-backed keyring at launch.
- Back up the keyring volume! Without it the data is unrecoverable.
  Add /var/lib/mysql-keyring to the backup set alongside db_data.
- ALTER TABLE ... ENCRYPTION='Y' rewrites each table; budget downtime
  proportional to data size (trivial right now, larger post-launch).
