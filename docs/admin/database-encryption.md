---
title: Database Encryption
description: Operating Project Alpha with MySQL-native encryption and recoverable keys.
---

# Database Encryption

Project Alpha uses separate controls for separate data-at-rest risks:

- ZFS or another encrypted host filesystem protects whole datasets while locked.
- `APP_ENCRYPTION_KEY` protects selected application credentials and private fields with AES-256-GCM.
- `BACKUP_ENCRYPTION_KEY` protects PA-created database and full backup archives.
- MySQL's `component_keyring_file` protects InnoDB application tables, the `mysql` system tablespace, redo logs, and undo logs.

Do not reuse one key for these different purposes. Do not replace an existing
`APP_ENCRYPTION_KEY`; PA must retain the original key to decrypt existing
application secrets.

## Deployment

The canonical Compose definition mounts the keyring manifest at
`/usr/sbin/mysqld.my`, the component configuration at
`/usr/lib64/mysql/plugin/component_keyring_file.cnf`, and a persistent keyring
volume at `/var/lib/mysql-keyring`. A one-shot initializer creates a valid
empty keyring only when the file is absent, then restricts the directory to the
MySQL user (`999:999`, mode `0700`) and keyring file to mode `0600`. It never
overwrites a non-empty existing keyring.

The database health check fails until the keyring reports `Active` and these
settings are enabled:

```text
default_table_encryption=ON
table_encryption_privilege_check=ON
innodb_redo_log_encrypt=ON
innodb_undo_log_encrypt=ON
```

After normal migrations, the one-shot migrator sets the application schema
default, encrypts the `mysql` system tablespace, converts every existing PA
InnoDB table that is not already encrypted, and verifies that none remain
unencrypted. Web and background services do not start if this step fails.

Existing tables are rebuilt during their first conversion. Before enabling
the deployment on an established installation:

1. Configure the same stable `BACKUP_ENCRYPTION_KEY` on `web` and `cron`.
2. Create and validate an encrypted `.full.zip` PA backup.
3. Store recovery copies of both PA keys outside the server.
4. Snapshot the database, config, uploads, and backups datasets.
5. Ensure enough free storage and use a maintenance window.

For TrueNAS bind mounts, create a persistent `mysql-keyring` dataset alongside
the database dataset and replace the `pa_mysql_keyring` named volume in the
canonical `docker-compose.yml` with the real dataset path. Never place the
keyring file inside `/var/lib/mysql`.

## Verification

Run these queries in the database container after deployment:

```sh
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -t -e \
  "SELECT * FROM performance_schema.keyring_component_status;"

MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -t -e \
  "SELECT @@global.default_table_encryption,
          @@global.table_encryption_privilege_check,
          @@global.innodb_redo_log_encrypt,
          @@global.innodb_undo_log_encrypt;"

MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -t -e \
  "SELECT ENCRYPTION, COUNT(*)
     FROM information_schema.INNODB_TABLESPACES
    WHERE NAME LIKE 'project_alpha/%'
    GROUP BY ENCRYPTION;
   SELECT NAME, ENCRYPTION
     FROM information_schema.INNODB_TABLESPACES
    WHERE NAME='mysql';"
```

The component must be `Active`, all four variables must be `1`, application
tablespaces must report only `Y`, and the `mysql` tablespace must report `Y`.

## Recovery

The keyring volume is required to open raw encrypted MySQL data files and ZFS
snapshots of the database dataset. Snapshot and back up the keyring dataset
after first conversion and after every `ALTER INSTANCE ROTATE INNODB MASTER
KEY` operation. Keep a recovery copy separately from the database dataset.

PA's logical `.full.zip` and `.db.zip` backups do not depend on the MySQL
keyring after creation. They are restored using `BACKUP_ENCRYPTION_KEY`, which
provides the recovery path if the raw MySQL keyring is lost.

Never delete or recreate the keyring volume while encrypted tables exist. If
the keyring is unavailable, stop the deployment and restore the matching
keyring snapshot or restore a verified logical PA backup into a clean database.

## Limits

MySQL-native encryption protects files at rest. Authorized SQL clients, the
running MySQL process, and application memory receive decrypted data. The
Community file keyring is also not a compliance-grade external KMS or HSM.
Deployments with regulatory key-custody requirements need a reviewed external
key-management design.
