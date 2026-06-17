# Woodpecker Assessment — Deferred to Phase 4

## Current Status

Woodpecker is running at https://woodpecker.ledgetoptechnologies.com/repos but is **NOT yet connected to GitHub** (no forge configured).

## Why We Chose GitHub Actions First

| Factor | GitHub Actions | Woodpecker on TrueNAS |
|--------|---------------|----------------------|
| Setup time | Immediate (add YAML files) | Hours (VM provisioning, OAuth config) |
| Infrastructure | Zero (GitHub hosts runners) | Requires Ubuntu VM inside TrueNAS |
| Cost | Free for public repos | Requires VM resources (RAM, disk) |
| Documentation | Massive community + Stack Overflow | Smaller community |
| Integration | Native GitHub PR checks | Needs OAuth app + webhook setup |
| Docker support | Built-in (`runs-on: ubuntu-latest`) | Needs Docker socket access inside TrueNAS |

## TrueNAS Challenge

TrueNAS Scale uses **k3s/containerd**, not Docker. Woodpecker agent needs Docker socket (`/var/run/docker.sock`). Running Woodpecker on TrueNAS Scale requires:

1. Create an Ubuntu VM inside TrueNAS (4GB RAM, 50GB disk)
2. Install Docker inside the VM
3. Run Woodpecker server + agent in Docker Compose
4. Port-forward the Woodpecker UI through TrueNAS
5. Create GitHub OAuth app and connect it

This is **non-trivial** and blocks the MVP release.

## Decision

**Phase 1 (NOW):** Use GitHub Actions for CI/CD. Works today, zero infra.
**Phase 4 (LATER):** Evaluate migrating to Woodpecker once PA is live and stable.

## When to Revisit

- PA is billing real customers on `main`
- Beau has time to provision a TrueNAS VM
- Edgar is comfortable with the existing GitHub Actions workflow
- Cost/dependency analysis favors self-hosted CI over GitHub-hosted

## Woodpecker `.woodpecker.yml` Sketch (Future)

```yaml
steps:
  build:
    image: docker/compose:latest
    commands:
      - cp .env.example .env
      - docker compose up -d --build
      - sleep 15
      - docker compose exec web php src/migrations/run_migrations.php --verbose
      - curl -f http://localhost:1627 || exit 1
```

This is intentionally left as a sketch. Do not implement until Phase 4 begins.
