#!/bin/sh
set -eu

if [ -z "${MYSQL_ROOT_PASSWORD:-}" ]; then
  exit 1
fi

status="$(MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql \
  --protocol=TCP -h 127.0.0.1 -uroot -N -s -e \
  "SELECT IF(
    EXISTS(
      SELECT 1
      FROM performance_schema.keyring_component_status
      WHERE STATUS_KEY='Component_status' AND STATUS_VALUE='Active'
    )
    AND @@global.default_table_encryption=1
    AND @@global.table_encryption_privilege_check=1
    AND @@global.innodb_redo_log_encrypt=1
    AND @@global.innodb_undo_log_encrypt=1
    AND @@global.binlog_encryption=1,
    1,
    0
  )" 2>/dev/null || true)"

[ "$status" = "1" ]
