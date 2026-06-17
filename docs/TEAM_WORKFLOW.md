# Project Alpha — Team Workflow

## Branch Model

```
main        <- Production (port 1627). NEVER push directly.
  ^
staging     <- Integration testing (port 1628). Auto-deploy on push.
  ^
dev         <- Active development. Daily work happens here.
  ^
feature/*   <- Individual features. Branch from dev, PR back to dev.
```

## Rules

1. **No direct pushes to `main` or `staging`** — always via Pull Request
2. **Daily work on `dev`** — Edgar and Beau branch from `dev`
3. **Feature branches:** `feature/stripe-webhook-fix`, `feature/client-dropdown`
4. **PR to `dev`:** Every feature -> PR -> review -> merge
5. **PR to `staging`:** When `dev` is stable, PR `dev` -> `staging`
6. **PR to `main`:** Only from `staging`, after testing on staging server

## How Edgar Works

```bash
# Edgar's machine
git clone https://github.com/ledgetoptechnologies/Project-Alpha.git
cd Project-Alpha
git checkout dev
git pull origin dev
git checkout -b feature/add-hemp-template
# ... make changes ...
git add -A && git commit -m "feat: add hemp-for-horses invoice template"
git push origin feature/add-hemp-template
# Then open PR on GitHub: feature/add-hemp-template -> dev
```

## How Beau Reviews

1. Open GitHub -> Pull Requests
2. Click the PR Edgar made
3. Check "Files changed" tab
4. If CI passes (green checkmark) -> click "Merge pull request"
5. Delete the feature branch after merge

## Environments

| Name | URL | Branch | Purpose |
|------|-----|--------|---------|
| Production | http://localhost:1627 | `main` | Live customer data |
| Staging | http://localhost:1628 | `staging` | Safe testing |
| Development | http://localhost:1627 | `dev` | Local dev |

## Starting Staging Locally

```bash
cd /home/bkoltz/Project-Alpha
docker compose -f docker-compose.staging.yml up -d
curl http://localhost:1628   # Should show login page
```

## GitHub Actions

- Every PR triggers CI (build + health check + PHP syntax check)
- Every push to `staging` auto-deploys to the staging server
- Add GitHub Secrets (Settings -> Secrets and variables -> Actions):
  - `STAGING_HOST` — your server IP
  - `STAGING_USER` — `bkoltz`
  - `STAGING_SSH_KEY` — private key for SSH deployment

## Troubleshooting

- **Port 1628 in use:** `ss -tlnp | grep 1628` then `docker compose -f docker-compose.staging.yml down`
- **Staging DB corrupt:** `docker compose -f docker-compose.staging.yml down -v` then `up -d` (loses data — OK for staging)
- **CI failing:** Check Actions tab for logs; usually `.env` is missing or Docker isn't running
