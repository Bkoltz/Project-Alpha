#!/usr/bin/env bash
set -e

# wait for db to be healthy (simple loop)
echo "Waiting for DB..."
retries=30
while ! mysqladmin ping -h db --silent; do
  retries=$((retries-1))
  if [ $retries -le 0 ]; then
    echo "DB did not become available"
    exit 1
  fi
  sleep 2
done

# Start apache in foreground
apache2-foreground