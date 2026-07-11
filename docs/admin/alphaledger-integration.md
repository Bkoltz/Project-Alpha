---
title: AlphaLedger Integration
description: Connect AlphaLedger to Project Alpha with scoped APIs, signed webhooks, and durable reconciliation.
---

# AlphaLedger Integration

Project Alpha owns PA users, clients, projects, AlphaLedger project assignments, invoices, customer payments, and employee-pay payment status. AlphaLedger owns time entries, breaks, approvals, corrections, and pay-accrual calculations. The applications keep separate authentication and databases.

## Connect

1. Deploy migration `0034_alphaledger_integration.sql` and restart both the PA web and cron containers.
2. In PA, create a dedicated API key whose **only** scope is **AlphaLedger integration**. Restrict its source IP when the deployment has a stable address; PA will reject full-access keys. If no stable source IP is possible, the enabling administrator must explicitly acknowledge that exception, and PA records it in the authorization policy and audit log.
3. Open **Settings → AlphaLedger**. Choose that key, enter the exact AL callback URL, acknowledge the ownership boundary, then reauthenticate with the current administrator password and a live TOTP code. Synchronization is disabled by default and no handshake or event exchange is allowed before this step.
4. Copy the immutable PA business ID shown there. In AlphaLedger, enter the PA base URL, that expected business ID, the same API key, and the exact callback URL authorized in PA.
5. After AL completes its handshake, assign employees from each PA project’s Edit page.
6. Confirm the suggested PA-person links in AlphaLedger. Links are never accepted solely from a fuzzy match; PA replays active assignments during reconciliation after links are confirmed.

PA exposes the versioned endpoints under `/api/v1/integrations/alphaledger/`. AlphaLedger imports approved billable time into PA’s tracked-time queue, where it can be added to a draft invoice. Approved pay accruals appear under **Assets & Expenses → Employee Pay**; marking one paid, reopened, or void sends a signed status event to AL.

When AL negotiates `operational_ledger_v1`, PA also receives a read-only operational mirror at **Financial → Ledger**. Administrators can inspect employees and identity links, observed projects and assignments, every time-entry state, breaks, corrections, and pay accruals. The Ledger remains visible but stale/read-only after disconnect until an administrator purges it with password and TOTP. Employee Pay is shown separately and never changes PA Income, Expenses, or Net Profit.

While synchronization is enabled, PA’s legacy timer, manual time creation, editing, and deletion are disabled. The PA time page becomes a read-only approved-time view, while adding unbilled approved time to an invoice remains available. PA refuses enablement while any PA timer is still running. AL-originated entries remain immutable in PA even after synchronization is later disabled; corrections must always come from their owning system.

## Security

- The API key is stored only as its SHA-256 hash by PA and should use the `alphaledger.sync` scope.
- PA requires two independent authorization locks: the dedicated scoped key and an enabled administrator policy bound to that exact key and callback URL.
- Enabling, changing, or disabling the policy requires a PA administrator’s password plus live TOTP. Administrators without enabled TOTP cannot authorize the integration.
- PA generates a separate 256-bit webhook secret during installation, returns it once to AL, and stores it encrypted with `APP_ENCRYPTION_KEY`.
- Webhook HMAC covers `X-PA-Timestamp + "." + raw_body`; PA sends `X-PA-Event-ID`, `X-PA-Timestamp`, and `X-PA-Signature`.
- Production callbacks require HTTPS. For an explicitly trusted HTTP-only private network, set `ALPHALEDGER_ALLOW_HTTP_CALLBACKS=true`.
- To restrict outbound webhook registration, set `ALPHALEDGER_CALLBACK_HOSTS` to a comma-separated exact hostname allowlist.
- PA does not follow webhook redirects. Keep MySQL private and terminate TLS at the reverse proxy.
- Operational Ledger batches require the dedicated bearer key plus a direction-bound HMAC over timestamp, method, exact path, and body hash. Requests older than five minutes, bodies over one MiB, unknown fields, replayed idempotency keys with different content, and revision conflicts fail closed.
- The PA settings page can rotate the encrypted webhook secret. Rotation disables the installation until AL repeats its handshake and receives the new secret.

## Reliability and Operations

The PA cron service captures owned-object changes every minute and retries webhook delivery with exponential backoff and jitter. AL also pulls the ordered change feed at recovery and at least every six hours, so a lost webhook does not lose state. Incoming AL requests require an `Idempotency-Key`; PA stores the request hash and original response for 30 days and rejects reuse with different content.

The AlphaLedger settings page shows installation health, consecutive failures, pending and attention events, imported record counts, open ownership conflicts, and recent delivery errors. PA also shows administrators a global warning when the authorized connection is incomplete, degraded, has exhausted delivery retries, or has unresolved ownership conflicts. Project assignment controls and the empty Employee Pay area stay hidden when the optional integration is unused.

Disabling synchronization immediately blocks every AL API endpoint and all outbound delivery while preserving imported history and queued audit evidence. Revoking the approved key, broadening it beyond the dedicated AL scope, or removing its IP allowlist without a recorded exception automatically disables the policy and installations.

Corrections to already invoiced time and changes to already paid accruals are accepted into a conflict queue without silently rewriting finalized PA financial records. Review `alphaledger_sync_conflicts` before resolving the affected invoice or payment manually.

Run a sync manually with:

```bash
docker compose exec cron php /var/www/src/cron/sync_alphaledger.php
```

Check `/var/www/config/logs/cron/cron.log`, `cron_job_runs`, `alphaledger_events`, and `alphaledger_sync_conflicts` when troubleshooting.
