# Staging Environment Setup

## Quick Start

```bash
cd /home/bkoltz/Project-Alpha
docker compose -f docker-compose.staging.yml up -d
curl http://localhost:1628   # Should show login page
```

## What Staging Is

- **Port 1628** (production uses 1627)
- **Database** `project_alpha_staging` (separate from production)
- **Docker volumes** `db_staging_data` and `staging-uploads` (isolated)
- **Stripe test keys** only (never live keys)
- **Environment banner** `APP_ENV=staging` shown in app UI

## Files

- `docker-compose.staging.yml` — Staging Docker Compose
- `.env.staging` — Staging credentials (gitignored)

## Commands

```bash
# Start staging
docker compose -f docker-compose.staging.yml up -d

# View logs
docker compose -f docker-compose.staging.yml logs -f

# Stop staging
docker compose -f docker-compose.staging.yml down

# Wipe staging DB (destructive — OK for staging)
docker compose -f docker-compose.staging.yml down -v
docker compose -f docker-compose.staging.yml up -d

# Access staging DB
docker compose -f docker-compose.staging.yml exec db-staging mysql -uappuser -p -D project_alpha_staging
```

## Verification

```bash
# Both environments should respond
curl -s http://localhost:1627 | head -3   # Production (main branch)
curl -s http://localhost:1628 | head -3   # Staging (staging branch)
```

## Auto-Deploy

Every push to `staging` branch triggers GitHub Actions deploy:
- SSH to server
- Pull `staging` branch
- Rebuild containers
- Run migrations
- Verify health check on port 1628

Requires GitHub Secrets: `STAGING_HOST`, `STAGING_USER`, `STAGING_SSH_KEY`

## Troubleshooting

- **Port 1628 in use:** `ss -tlnp | grep 1628` to find the process
- **DB connection refused:** Wait 30s after `up -d` for MySQL init
- **Uploads not persisting:** `staging-uploads` Docker volume is used, not host bind mount
