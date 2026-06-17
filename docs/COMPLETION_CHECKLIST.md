# Project Alpha MVP Completion Checklist

## What "Done" Looks Like for MVP

This checklist tracks whether Project Alpha is ready for real customer use.

## Phase 1: Foundation Fixes (COMPLETE)

- [x] **cron/src/ eliminated** — cron uses shared `src/` mount, no duplicate code trees
- [x] **Migration drift fixed** — cumulative migration 015 syncs schema, deprecated 000 removed
- [x] **Stripe end-to-end tested** — checkout sessions, surcharge, both webhook handlers
- [x] **No raw card storage** — DB audit confirms only Stripe tokens stored
- [x] **Smoke test passed** — app loads, DB connected, public pages work

## Phase 2: Staging + CI/CD (COMPLETE)

- [x] **docker-compose.staging.yml** — runs on port 1628, separate DB + volumes
- [x] **.env.staging.example** — template with placeholders (real .env gitignored)
- [x] **Staging verified** — port 1628 responds, DB initialized, production untouched
- [x] **GitHub Actions CI** — builds, health checks, PHP syntax on every PR
- [x] **GitHub Actions deploy** — auto-deploy to staging on push to `staging` branch
- [x] **Branch `staging` created** — pushed to origin

## Phase 3: Team Workflow (COMPLETE)

- [x] **docs/TEAM_WORKFLOW.md** — branch model, how Edgar works, how Beau reviews
- [x] **docs/STAGING_SETUP.md** — commands, verification, troubleshooting
- [x] **docs/WOODPECKER_ASSESSMENT.md** — deferred to Phase 4 with rationale
- [x] **docs/STRIPE_TEST_RESULTS.md** — documented all test outcomes

## Remaining Before Billing Real Customers

These are the final items before PA handles real invoices:

### High Priority

- [x] **Configure webhook secret** in `.env` — `stripe_webhook_secret_enc` now configured as `plain::whsec_J7CIw0H72YDsVyFaSBjLwEV9cBGpJ7jf`
- [ ] **Test auto-charge recurring** — requires real `stripe_customer_id` + `stripe_payment_method_id` in invoice
- [ ] **Production deployment** — merge `staging` → `main`, test on port 1627
- [ ] **Cloudflare tunnel** — ensure pa.ledgetopdroneservices.com routes to port 1627
- [ ] **Backups** — automated DB backup before going live

### Medium Priority

- [ ] **GitHub branch protection** — protect `main` and `staging` (require PR + CI pass)
- [ ] **GitHub Secrets for deploy** — `STAGING_HOST`, `STAGING_USER`, `STAGING_SSH_KEY`
- [ ] **Stripe live keys** — swap test keys for live when ready to bill
- [ ] **Email configuration** — SMTP for invoice notifications, payment receipts
- [ ] **SSL certificate** — Let's Encrypt for production domain

### Low Priority (Post-MVP)

- [ ] **Woodpecker CI migration** — Phase 4, requires TrueNAS VM
- [ ] **PHPUnit test suite** — automated tests beyond CI syntax check
- [ ] **Admin dashboard improvements** — reporting, analytics
- [ ] **Mobile responsiveness** — verify on phones/tablets
- [ ] **Performance tuning** — DB indexes, query optimization

## Branch Strategy

```
main        <- Production (port 1627). NEVER push directly.
  ^
staging     <- Integration testing (port 1628). Auto-deploy on push.
  ^
dev         <- Active development. Daily work happens here.
  ^
feature/*   <- Individual features. Branch from dev, PR back to dev.
```

## How to Move Forward

1. **Edgar clones repo, branches from `dev`**
2. **Edgar makes feature, pushes, opens PR to `dev`**
3. **Beau reviews, CI passes, merges PR**
4. **When dev is stable, PR `dev` → `staging`**
5. **Staging auto-deploys to port 1628 — test there**
6. **When staging is good, PR `staging` → `main`**
7. **Production bills customers on port 1627**
