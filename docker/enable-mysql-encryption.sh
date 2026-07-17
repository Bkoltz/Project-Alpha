#!/usr/bin/env bash
set -euo pipefail

required="$(printf '%s' "${MYSQL_ENCRYPTION_REQUIRED:-false}" | tr '[:upper:]' '[:lower:]')"
if [ "$required" != "true" ] && [ "$required" != "1" ] && [ "$required" != "yes" ]; then
  echo "MySQL native encryption is not required for this deployment; skipping tablespace conversion."
  exit 0
fi

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${MYSQL_DATABASE:-project_alpha}"
ROOT_USER="${MYSQL_ROOT_USER:-root}"
ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-}"

if [[ ! "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "MySQL encryption failed: MYSQL_DATABASE contains unsupported characters." >&2
  exit 1
fi
if [ -z "$ROOT_PASSWORD" ]; then
  echo "MySQL encryption failed: MYSQL_ROOT_PASSWORD is required." >&2
  exit 1
fi

mysql_root() {
  MYSQL_PWD="$ROOT_PASSWORD" mysql --skip-ssl \
    -h "$DB_HOST" -P "$DB_PORT" -u "$ROOT_USER" "$@"
}

component_status="$(mysql_root -N -s -e \
  "SELECT STATUS_VALUE FROM performance_schema.keyring_component_status WHERE STATUS_KEY='Component_status' LIMIT 1")"
if [ "$component_status" != "Active" ]; then
  echo "MySQL encryption failed: component_keyring_file is not active." >&2
  exit 1
fi

encryption_defaults="$(mysql_root -N -s -e \
  "SELECT CONCAT(@@global.default_table_encryption, ':', @@global.table_encryption_privilege_check, ':', @@global.innodb_redo_log_encrypt, ':', @@global.innodb_undo_log_encrypt)")"
if [ "$encryption_defaults" != "1:1:1:1" ]; then
  echo "MySQL encryption failed: required table, privilege, redo, and undo encryption settings are not all enabled." >&2
  exit 1
fi

unsafe_table_count="$(mysql_root -N -s -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_type='BASE TABLE' AND table_name NOT REGEXP '^[A-Za-z0-9_]+$'")"
if [ "$unsafe_table_count" != "0" ]; then
  echo "MySQL encryption failed: the application schema contains table names that require manual review." >&2
  exit 1
fi

unsupported_engine_count="$(mysql_root -N -s -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_type='BASE TABLE' AND COALESCE(engine,'') <> 'InnoDB'")"
if [ "$unsupported_engine_count" != "0" ]; then
  echo "MySQL encryption failed: every application base table must use InnoDB." >&2
  exit 1
fi

echo "Setting the ${DB_NAME} schema encryption default..."
mysql_root -e "ALTER DATABASE \`$DB_NAME\` DEFAULT ENCRYPTION = 'Y';"

mysql_tablespace_encryption="$(mysql_root -N -s -e \
  "SELECT COALESCE(MAX(ENCRYPTION),'N') FROM information_schema.innodb_tablespaces WHERE NAME='mysql'")"
if [ "$mysql_tablespace_encryption" != "Y" ]; then
  echo "Encrypting the MySQL system tablespace..."
  mysql_root -e "ALTER TABLESPACE mysql ENCRYPTION = 'Y';"
fi

ddl_file="$(mktemp)"
trap 'rm -f "$ddl_file"' EXIT
mysql_root -N -s -e "
  SELECT CONCAT(
    'ALTER TABLE ', CHAR(96), t.TABLE_SCHEMA, CHAR(96), '.',
    CHAR(96), t.TABLE_NAME, CHAR(96), ' ENCRYPTION = ''Y'';'
  )
  FROM information_schema.TABLES t
  LEFT JOIN information_schema.INNODB_TABLESPACES ts
    ON ts.NAME = CONCAT(t.TABLE_SCHEMA, '/', t.TABLE_NAME)
  WHERE t.TABLE_SCHEMA = '$DB_NAME'
    AND t.TABLE_TYPE = 'BASE TABLE'
    AND t.ENGINE = 'InnoDB'
    AND COALESCE(ts.ENCRYPTION, 'N') <> 'Y'
  ORDER BY t.TABLE_NAME
" > "$ddl_file"

table_count="$(wc -l < "$ddl_file" | tr -d '[:space:]')"
if [ "$table_count" -gt 0 ]; then
  echo "Encrypting ${table_count} existing InnoDB tables. This may take time..."
  mysql_root < "$ddl_file"
else
  echo "All application InnoDB tables are already encrypted."
fi

remaining="$(mysql_root -N -s -e "
  SELECT COUNT(*)
  FROM information_schema.TABLES t
  LEFT JOIN information_schema.INNODB_TABLESPACES ts
    ON ts.NAME = CONCAT(t.TABLE_SCHEMA, '/', t.TABLE_NAME)
  WHERE t.TABLE_SCHEMA = '$DB_NAME'
    AND t.TABLE_TYPE = 'BASE TABLE'
    AND t.ENGINE = 'InnoDB'
    AND COALESCE(ts.ENCRYPTION, 'N') <> 'Y'
")"
schema_default="$(mysql_root -N -s -e \
  "SELECT DEFAULT_ENCRYPTION FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DB_NAME'")"
mysql_tablespace_encryption="$(mysql_root -N -s -e \
  "SELECT COALESCE(MAX(ENCRYPTION),'N') FROM information_schema.INNODB_TABLESPACES WHERE NAME='mysql'")"

if [ "$remaining" != "0" ] || [ "$schema_default" != "YES" ] || [ "$mysql_tablespace_encryption" != "Y" ]; then
  echo "MySQL encryption verification failed: remaining=$remaining schema_default=$schema_default mysql_tablespace=$mysql_tablespace_encryption" >&2
  exit 1
fi

echo "MySQL native encryption verified: keyring active, schema/table/system tablespaces encrypted, redo and undo encryption enabled."
