# Project Alpha — Operational Context (detailed; memory points here)

Updated 2026-06-23. Maintained by Hermes. Memory holds compact facts;
this file holds the detail that's too long for the 2,200-char memory budget.

## Deployment topology
- Prod: TrueNAS 192.168.50.80:1627 (Custom App), admin pw Therockiscute2080!
- Staging: TrueNAS 192.168.50.80:1628 (Custom App), admin pw Demo123!
- Prod often unreachable from Hermes host (LAN/exposure varies)
- dev == main at e902b22 (both identical). All fixes in :latest on GHCR.
- GHCR packages MUST stay PUBLIC for anonymous TrueNAS pull.
- start.sh re-upserts admin pw from env each boot via getenv() (special-char safe)
- Lockout: 5 failed logins / 15 min per account (different error than "invalid creds")
- TrueNAS Custom App re-reads its pasted compose each container start; edit env there + recreate (Pull+Up), NOT in the repo file

## Production data rules (CRITICAL)
Prod now has a LIVE database with real client data. Rules:
1. All schema changes = scripted migrations (database/migrations/*.sql via run_migrations.php). NEVER direct edits to init.sql or live tables.
2. All changes backward compatible (nullable/defaults for new cols; no drop-rename in one step).
3. init.sql stays source of truth for fresh installs — any migration must ALSO update init.sql.
4. Test migrations on staging first.

## Organizations: ONE instance, TWO orgs
Ledge Top Technologies LLC is one company; LTDS is a division/DBA, taxes cumulative at year end.
PA multi-tenant model (organizations + user_organizations) supports this natively.
Use ONE PA instance with two organizations ("Ledge Top Technologies" + "Ledge Top Drone Services").
Toggle orgs in one admin login. Shared DB, backup, deploy, Stripe.
CAVEAT: app_config.brand_name is GLOBAL, not per-org. Invoice headers currently show one brand for all.
Per-brand headers (drone invoices say "Ledge Top Drone Services") = optional small enhancement.

## Surcharge — DO NOT ENABLE YET (compliance gap)
Split mode exists (StripeFeeCalculator, default 50% — client pays portion). But:
- BUG: InvoiceSurcharge.php line 20 gates on paymentMethod in ['stripe','card'] — applies to ANY
  Stripe payment including DEBIT cards. Federal law (Durbin Amendment) PROHIBITS debit surcharges.
  Fix needed: restrict Stripe Checkout to credit-card-only payment methods, OR refund surcharge
  if funding type turns out to be debit.
- BUG: surcharge rate is a free-form config, not capped at actual merchant rate. Visa/Mastercard
  rules say surcharge CANNOT exceed your actual merchant discount rate. Cap client_pays to real fee.
- What's done right: surcharge is a separate line item labeled "Credit Card Processing Fee" (proper disclosure).
- Before enabling: (1) fix debit gap, (2) cap rate, (3) register with Visa 30 days prior, (4) verify receipt itemization.
- Contract clause for surcharge = optional good practice, NOT legally required. Disclosure + Visa registration is the bar.

## CI/CD
- Pull-only GHCR (no build-on-NAS, no self-hosted runner).
- CI builds :dev/:cron on dev push, :latest/:cron-latest on main push.
- Smoke-test CI (ci.yml, job=smoke-test) guards web-image-identity, DB-over-TCP, CSP-clean-over-HTTP.
- Promotion = GATED AUTO-MERGE (decided): dev->main merges only on green smoke-test; user redeploys TrueNAS manually.
- PENDING: branch protection + auto-merge UI (token lacks admin scope; must do in GitHub UI).
  See /home/bkoltz/Project-Alpha/docs/CI-CD-NEXT-STEPS.md for the exact steps.

## Version display (shipped)
APP_VERSION build-arg (git describe) baked into image. app_version() helper: env -> /var/www/APP_VERSION -> 'dev'.
Footer shows vX.Y.Z. api-dashboard-summary returns 'version' key.

## Dashboard fixes (shipped)
- home.php: real system RAM from /proc/meminfo (was PHP process memory). Income(90d) card removed.