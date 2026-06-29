# Project Alpha — TrueNAS SCALE Installation

## Requirements
- TrueNAS SCALE 24.10 (Electric Eel) or later
- A dataset for PA data (recommended: `apps/project-alpha`)

## Quick Install via Custom App (Docker Compose)

1. **Create datasets** on your TrueNAS pool:
   - `tank/apps/project-alpha/uploads`
   - `tank/apps/project-alpha/config`
   - `tank/apps/project-alpha/backups`
   - `tank/apps/project-alpha/db`

2. **Go to** Apps > Custom Apps > Install from YAML

3. **Paste** the contents of `docker-compose.truenas.yml` (in this repo)

4. **Change all passwords** — search for `changeme` and replace with strong passwords

5. **Deploy** — PA will be available at `http://your-truenas-ip:1627`

6. **First login:** Use `admin@project-alpha.local` with your `ADMIN_PASSWORD`

7. **Configure Stripe:** Go to Settings > Billing to enter your Stripe API keys

## Manual Install via Docker Compose (SSH)

If you prefer SSH over the UI:

```bash
# Create datasets
zfs create tank/apps/project-alpha
zfs create tank/apps/project-alpha/uploads
zfs create tank/apps/project-alpha/config
zfs create tank/apps/project-alpha/backups
zfs create tank/apps/project-alpha/db

# Clone the repo
cd /mnt/tank/apps/project-alpha
git clone https://github.com/ledgetoptechnologies/Project-Alpha.git .

# Edit docker-compose.truenas.yml — change all passwords!
vi docker-compose.truenas.yml

# Deploy
docker compose -f docker-compose.truenas.yml up -d
```

## Updating

```bash
cd /mnt/tank/apps/project-alpha
docker compose -f docker-compose.truenas.yml pull
docker compose -f docker-compose.truenas.yml up -d
```

## Backup

PA data is stored in the datasets you created. Use TrueNAS snapshot and replication tasks to back up:
- `tank/apps/project-alpha/db` (database)
- `tank/apps/project-alpha/uploads` (uploaded files)
- `tank/apps/project-alpha/config` (configuration)

The built-in cron container also performs daily MySQL dumps to the backups volume.

## Support
- GitHub: https://github.com/ledgetoptechnologies/Project-Alpha/issues
- Website: https://project-alpha.tech
- Email: bkoltz@ledgetoptechnologies.com