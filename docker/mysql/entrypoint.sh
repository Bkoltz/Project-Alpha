#!/bin/sh
set -eu

if [ "${1:-}" = "mysqld" ] || [ "${1#-}" != "${1:-}" ]; then
  keyring_dir=/var/lib/mysql-keyring
  keyring_file="$keyring_dir/component_keyring_file"

  if [ "$(id -u)" -ne 0 ]; then
    echo "Project Alpha MySQL startup failed: keyring initialization requires the container's default root user." >&2
    exit 1
  fi

  install -d -o mysql -g mysql -m 0700 "$keyring_dir"

  if [ -L "$keyring_file" ] || { [ -e "$keyring_file" ] && [ ! -f "$keyring_file" ]; }; then
    echo "Project Alpha MySQL startup failed: the keyring path is not a regular file." >&2
    exit 1
  fi

  if [ ! -e "$keyring_file" ]; then
    umask 077
    temporary_file="$keyring_dir/.component_keyring_file.$$"
    trap 'rm -f "$temporary_file"' EXIT HUP INT TERM
    printf '%s' '{"version":"1.0","elements":[]}' > "$temporary_file"
    chown mysql:mysql "$temporary_file"
    chmod 0600 "$temporary_file"
    mv "$temporary_file" "$keyring_file"
    trap - EXIT HUP INT TERM
    echo "Initialized the persistent Project Alpha MySQL keyring."
  elif [ ! -s "$keyring_file" ]; then
    echo "Project Alpha MySQL startup failed: the existing keyring file is empty." >&2
    exit 1
  fi

  chown mysql:mysql "$keyring_file"
  chmod 0600 "$keyring_file"
fi

exec /usr/local/bin/docker-entrypoint.sh "$@"
