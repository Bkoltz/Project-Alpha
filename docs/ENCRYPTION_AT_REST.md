# Encryption at Rest — InnoDB Tablespace Encryption

Status: DOCUMENTED, NOT YET APPLIED (requires a maintenance window).

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
