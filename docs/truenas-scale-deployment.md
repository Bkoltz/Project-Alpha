# TrueNAS Scale Deployment Guide for Project Alpha

> Target: self-hosted PA SaaS for personal/business use on a TrueNAS Scale box.
> Assumes Project Alpha's existing Docker Compose stack (web + cron + MySQL) and a desire to feed PA KPIs into the Hermes Command Center.

---

## 1. What you need

| Item | Recommendation |
|---|---|
| TrueNAS Scale | Latest stable release with Apps catalog enabled |
| Storage | At least one pool for app data and backups |
| Network | Static LAN IP for the NAS; port 443/80 forwarded if external access is desired |
| Images | `ghcr.io/ledgetoptechnologies/project-alpha:latest` and `ghcr.io/ledgetoptechnologies/project-alpha:cron-latest` (or your own published tags) |
| Secrets | MySQL root/app passwords, admin password, Stripe keys (entered in UI) |

---

## 2. Deployment options

### Option A - Compose with published images (recommended)
Use the main `docker-compose.yml`. It does not contain `build:` sections, so TrueNAS does not need the local source tree or `Dockerfile` in its app working directory.

This avoids the TrueNAS error:

```text
failed to solve: failed to read dockerfile: open Dockerfile: no such file or directory
```

Set `PROJECT_ALPHA_DATA_DIR` to your app dataset path, for example `/mnt/tank/apps/project-alpha`.

### Option B - Custom App wizard
Use TrueNAS Scale's **Custom App** wizard and enter the same images, environment variables, ports, and mounts from `docker-compose.yml`.

### Option C - Build on TrueNAS
If TrueNAS Scale still supports the **Docker Compose** app type, upload the full project directory as an app.

Use a local build override only when the compose file, `Dockerfile`, `src/`, `public/`, `database/`, `docker/`, and `cron/` directories are all present in the same uploaded project directory. The main `docker-compose.yml` is intended to pull images from GitHub Container Registry.

---

## 3. Prepare the host storage

1. Create a dataset for the app, e.g. `tank/apps/project-alpha`.
2. Inside it create sub-datasets or directories:
   - `uploads` - mounted to `/var/www/src/uploads`
   - `config` — app config (encrypted in UI is fine)
   - `backups` — DB dumps and optional file backups
   - Scheduled job output is stored inside the config volume at `logs/cron`
   - `db_data` - MySQL data directory
3. Set ACL owner to the user ID the container runs as (default `33` for `www-data`, or whatever the Dockerfile uses).

---

## 4. Custom App manifest

Create a file named `truenas-project-alpha.yaml` and import it, or fill the wizard manually.

```yaml
# TrueNAS Scale Custom App — Project Alpha
apiVersion: v1
appVersion: "1.0.0"
categories:
  - business
name: project-alpha
version: 1.0.0
description: Project Alpha — invoices, quotes, contracts, expenses
annotations:
  title: Project Alpha
  notes: |
    Change all default passwords before first use.
    Browse to the app UI, log in as admin@project-alpha.local,
    then go to Settings to set Stripe keys.
```

Use these container settings in the wizard:

### 4.1 web container

| Field | Value |
|---|---|
| Image repository | `ghcr.io/ledgetoptechnologies/project-alpha` |
| Image tag | `latest` |
| Container port | `80` |
| Host port / Node port | `1627` (or use TrueNAS ingress on 80/443) |
| Environment: `DB_HOST` | `localhost` (when sharing a sidecar) or service name |
| Environment: `DB_PORT` | `3306` |
| Environment: `MYSQL_DATABASE` | `project_alpha` |
| Environment: `MYSQL_USER` | `appuser` |
| Environment: `MYSQL_PASSWORD` | `<generate strong>` |
| Environment: `MYSQL_ROOT_PASSWORD` | `<generate strong>` |
| Environment: `ADMIN_PASSWORD` | `<generate strong>` |
| Environment: `TRUSTED_PROXIES` | `172.16.0.0/12 192.168.0.0/16 10.0.0.0/8 127.0.0.0/8` |
| Mount: uploads | `/mnt/tank/apps/project-alpha/uploads` -> `/var/www/src/uploads` |
| Mount: config | `/mnt/tank/apps/project-alpha/config` → `/var/www/config` |
| Mount: backups | `/mnt/tank/apps/project-alpha/backups` → `/var/www/backups` |

### 4.2 db container (sidecar)

| Field | Value |
|---|---|
| Image | `mysql:8` |
| Container port | `3306` |
| Environment: `MYSQL_ROOT_PASSWORD` | same as web |
| Environment: `MYSQL_DATABASE` | `project_alpha` |
| Environment: `MYSQL_USER` | `appuser` |
| Environment: `MYSQL_PASSWORD` | same as web |
| Mount: data | `/mnt/tank/apps/project-alpha/db_data` → `/var/lib/mysql` |
| Mount: socket | shared named volume `mysql_socket` -> `/var/run/mysqld` |

### 4.3 cron container

| Field | Value |
|---|---|
| Image repository | `ghcr.io/ledgetoptechnologies/project-alpha` |
| Image tag | `cron-latest` |
| Command/args | use image default |
| Same DB env as web | yes |
| Same config/uploads/backups mounts as web | yes |
| Cron logs | Stored in the config mount at `/var/www/config/logs/cron` |

---

## 5. Networking choices

### A. LAN only
Set the web service to use a **NodePort** of `1627` and access via `http://<nas-ip>:1627`.

### B. External HTTPS (recommended)
Use TrueNAS Scale's **Ingress** feature or a reverse proxy:
1. Add an ingress rule for host `pa.yourdomain.com` pointing to the web service on port `80`.
2. Enable TrueNAS certificate (Let's Encrypt or imported).
3. The `TRUSTED_PROXIES` env already includes RFC-1918 ranges; add your proxy's CIDR if needed.

---

## 6. First-run checklist

1. Change the three placeholder passwords in the wizard before saving.
2. Start the app and wait for MySQL init to finish (check app logs).
3. Open the web UI and log in with `admin@project-alpha.local` and the `ADMIN_PASSWORD`.
4. **Settings > Billing**: add Stripe publishable + secret keys (test mode first).
5. **Settings > Company**: set legal name, address, logo.
6. **Settings > Taxes**: import Wisconsin rates if needed.
7. **Settings > API Keys**: create a key with scope `dashboard` for the Command Center connector.

---

## 7. Backups

The `cron` service runs `db_backup.sh` and writes `.sql.gz` files to `/var/www/backups`, which is mounted to the host at `tank/apps/project-alpha/backups`.

Additional recommendations:
- Snapshot the `tank/apps/project-alpha` dataset nightly.
- Replicate snapshots to a second pool or offsite TrueNAS.
- Back up `src/uploads` separately because it contains client files.

---

## 8. Connecting to the Hermes Command Center

The Command Center already has a Project Alpha tab at `/alpha`. To make it read live PA data:

1. In PA, create an API key with scope `dashboard`.
2. On the dashboard host, edit `/home/bkoltz/dashboard/pa_dashboard_config.py`:
   - `PA_API_BASE = "http://<nas-ip-or-domain>:1627"` (or HTTPS if using ingress)
   - `PA_API_KEY = "<dashboard-scope-key>"`
3. Restart the Command Center (`uvicorn main:app`).
4. `/alpha` will now show cards for clients, projects, quotes, contracts, invoices, 30-day revenue, and outstanding AR.

All dashboard API endpoints are read-only and limited to a `dashboard` scope.

---

## 9. Security hardening for production

- Do not expose MySQL port `3306` to the LAN/internet.
- Use HTTPS ingress and HSTS.
- Store passwords and Stripe keys only through the PA UI / DB; never commit them.
- Restrict TrueNAS app admin access to local admin accounts + 2FA.
- Keep the Project Alpha image updated and rebuild when security patches land.

---

## 10. Updating PA on TrueNAS Scale

1. Build or pull the new image tag.
2. In the TrueNAS app, edit the web/cron containers to use the new tag.
3. Save and wait for rollout. The `src` and `uploads` mounts persist across updates.
4. Verify `/alpha` health indicators stay green.

---

*No secrets are stored in this guide. Replace all placeholders with values generated in your environment.*
