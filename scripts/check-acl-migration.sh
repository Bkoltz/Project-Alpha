#!/usr/bin/env bash
set -euo pipefail
DB_HOST="${DB_HOST:-db}"
DB_NAME="${DB_NAME:-project_alpha}"
DB_USER="${DB_USER:-appuser}"
DB_PASS="${DB_PASS:-changeme_secure_pass}"
mysql -h "$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
"SELECT COUNT(*) FROM roles; SELECT COUNT(*) FROM role_permissions; SELECT COUNT(*) FROM user_permissions_overrides;" > /dev/null
mysql -h "$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
"SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='user_organizations' AND column_name='role_id';" | tail -1 | grep -q 1
