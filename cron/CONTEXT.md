# Cron Container Context

Last reviewed: 2026-06-28

This directory defines the cron container schedule and entrypoint. Scheduled PHP scripts live in `src/cron/` and are copied into the cron stage by the root `Dockerfile`.

## Files

- `crontab`: fixed UTC schedule
- `entrypoint.sh`: exports the environment for cron, waits for MySQL, prepares logs, and runs cron in the foreground
- `README.md`: authoritative schedule and operator commands
- `composer.json`: legacy/minimal metadata; the root multi-stage build uses root dependencies

## Deployment

- `dev` publishes `:cron`.
- `main` publishes `:cron-latest`.
- Published Compose does not bind-mount application source. Pull or rebuild the cron image after changing cron PHP, dependencies, the crontab, or the entrypoint.

All scheduled output is appended to `/var/www/config/logs/cron/cron.log`.
