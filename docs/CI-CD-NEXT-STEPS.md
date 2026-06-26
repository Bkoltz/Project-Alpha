# Project Alpha — Pending CI/CD setup (resume here)

Status as of 2026-06-23. All three prod bugs are FIXED and verified:
  - web Dockerfile stage now named `AS web` (was building cron-as-web)
  - DB connection over TCP (was a removed unix socket)
  - HSTS + CSP upgrade-insecure-requests gated behind actual-HTTPS
`main` is at commit 087c285 with all fixes. `:latest`/`:cron-latest` on GHCR
verified as real web/cron images. Smoke-test CI is GREEN on the GitHub runner
(dev @ e5a261d), job name = `smoke-test`, all 4 assertions pass.

================================================================
## 1. TODO TOMORROW — gated auto-merge (dev -> main)
================================================================
Decision already made: GATED AUTO-MERGE. dev->main merges itself ONLY when the
`smoke-test` check is green. Beau still manually redeploys TrueNAS (no auto-pull).

### Blocker: the repo push token lacks the "Administration" permission
API attempts to set these returned 403 ("Resource not accessible by personal
access token"). So either do the 2 UI steps below, OR regrant the token
Administration:write and Hermes can script it via:
  - PATCH /repos/.../  {"allow_auto_merge": true}
  - PUT  /repos/.../branches/main/protection
        {required_status_checks:{strict:true,contexts:["smoke-test"]},
         required_pull_request_reviews:null (0 approvals),
         enforce_admins:false, restrictions:null}

### UI steps (one-time, ~2 min)
1) Enable auto-merge:
   github.com/ledgetoptechnologies/Project-Alpha/settings -> Pull Requests
   -> check "Allow auto-merge"
2) Protect main (Rulesets UI):
   github.com/ledgetoptechnologies/Project-Alpha/settings/rules
   -> New ruleset -> New branch ruleset
   -> Name: protect-main ; Enforcement: Active
   -> Target: Include default branch (main)
   -> Rules:
        [x] Require a pull request before merging (Required approvals = 0)
        [x] Require status checks to pass -> add check: smoke-test
            [x] Require branches to be up to date before merging
        [x] Block force pushes
   -> Create

### After UI setup, Hermes will:
- Open a standing dev->main PR, enable auto-merge, and VERIFY it stays blocked
  until smoke-test passes then merges itself (proves the gate end-to-end).

### New promotion workflow once live
work on dev -> push -> (CI builds :dev, smoke-test runs) -> open dev->main PR
-> auto-merges on green -> CI builds :latest -> Beau redeploys TrueNAS prod (1627).
NOTE: direct `git push origin main` will be BLOCKED after protection — by design.

================================================================
## 2. PROD LOGIN BROKEN (high priority — needs Beau + NAS access)
================================================================
Symptom: prod login admin@project-alpha.local / changeme_admin_pass = "invalid
credentials". STAGING works with the same image; PROD does not.
Note: prod at 192.168.50.80:1627 was NOT reachable from the Hermes host during
this session (LAN/exposure/down — unknown), so this could not be diagnosed live.

Ranked hypotheses (most -> least likely):
  (A) Prod TrueNAS app config has a REAL ADMIN_PASSWORD set (good practice), so
      the literal default "changeme_admin_pass" is simply wrong for prod. start.sh
      upserts whatever ADMIN_PASSWORD is in the env on each boot, so the correct
      prod password is whatever is in the prod app's env, not the default string.
      -> FIX: log in with the prod ADMIN_PASSWORD from the TrueNAS app config, or
         reset it there and redeploy.
  (B) Stale prod db_data volume from a PRE-FIX deploy: the old image's start.sh
      did the admin upsert over the unix socket, which FAILED silently (|| true)
      because the socket volume was removed -> admin row never created/updated.
      After today's TCP fix + redeploy this should self-heal on next boot. Confirm
      prod is actually running the NEW :latest (087c285), then restart web once.
  (C) Account lockout / rate-limit after repeated failed attempts (rate_limiter.php,
      per-account + per-IP). Wait out the window or clear, then retry.
  (D) 2FA enabled on the prod admin row, or force_password_reset set.

Diagnostic commands to run on the NAS tomorrow (web container = pa web):
  docker exec <pa-web> printenv | grep ADMIN_PASSWORD
  docker exec <pa-db> mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -D project_alpha \
    -e "SELECT id,email,username,role,force_password_reset,
        (two_factor_secret IS NOT NULL) AS has_2fa, deleted_at
        FROM users WHERE email='admin@project-alpha.local';"
  docker logs <pa-web> 2>&1 | grep -iE 'admin|DB is ready|schema|password hash'
Confirm which image prod runs:
  docker inspect <pa-web> --format '{{.Image}}'  (should be the new ghcr :latest)

================================================================
## 3. DASHBOARD / DISPLAY BUGS (clarify scope first — likely PA, not CC)
================================================================
IMPORTANT: "system RAM wrong" and "income last 90 days" were NOT found in the
Command Center (port 5000) Python or templates. They almost certainly live in
PROJECT ALPHA's own UI:
  - "Income" is rendered in PA: src/views/pages/financial/financial-dashboard.php
    (KPI label "Income", computed from payments where status='succeeded' between
    a start/end date range). Also check PA home.php overview (the 466-line dev
    redesign with SVG charts) for an income/90-day card.
  - "System RAM" not located in CC at all — check PA admin/status panels, or
    confirm which screen Beau means. CC host stats live elsewhere if at all.
OPEN QUESTIONS FOR BEAU (could not ask — AFK):
  - Which app's dashboard shows the wrong RAM + the 90-day income? PA (home.php /
    financial-dashboard) or the Command Center overview?
  - "Income last 90 days shouldn't be there" — remove the card entirely, or change
    its window (e.g. to 30d / current month)?
Do NOT change financial-dashboard.php or home.php blind — needs the above answers
and a way to see the running screen.

================================================================
## 4. PA VERSION DISPLAY (nice-to-have, safe to build on dev)
================================================================
PA currently has NO version anywhere (no composer.json version, no VERSION file,
no APP_VERSION constant). `git describe` gives e.g. 0.2.1-703-g087c285.
Plan (do on dev, flows through normal CI->staging->prod):
  - Add a VERSION file or APP_VERSION constant; OR have docker-publish.yml stamp
    the build (git short SHA + tag) into an env/file baked into the image.
  - Surface it in PA footer/admin AND expose via the dashboard API
    (api-dashboard-summary) so the Command Center alpha tab can show "PA vX.Y.Z".
  - Command Center connector: project_alpha_routes.py::_pa_metrics() already pulls
    api-dashboard-summary — add a "version" key there and render in v3_alpha.html.

================================================================
## 5. COMMAND CENTER <-> PA SYNC (side quest, scope with Beau)
================================================================
CC has a working PA connector: project_alpha_routes.py
  - PA_API_BASE_URL = http://localhost:1627 (pa_dashboard_config.py) — NOTE this
    points at localhost:1627, which on the CC host is now EMPTY (we removed the
    local PA stack). If CC should read PROD PA, point this at the TrueNAS prod URL
    (e.g. http://192.168.50.80:1627) and set a real PA_API_KEY with 'dashboard'
    scope (currently "REPLACE_WITH_PA_API_KEY").
  - _pa_metrics() pulls api-dashboard-summary + api-financial-summary(days=30) ->
    clients/projects/quotes/contracts/invoices/revenue_30d/outstanding.
  - Templates: templates/v3_alpha.html + v3_alpha_kpis.html (KPI block),
    v3_alpha_graph.html (graphify tab).
Beau's ask: "update everything on CC with PA changes / view everything needed for
PA workflow." Needs a concrete list of what PA workflow views he wants surfaced
(quotes pipeline? contract signing status? invoice aging? the doc lifecycle
Quote->Contract->Invoice?). Scope this with him before building.

================================================================
## 6. Host cleanup done this session
================================================================
- Removed stale local stacks (project-alpha :1627, project-alpha-staging :1628),
  their volumes (incl. the old mysql_socket relic), and 4 obsolete
  bkoltz/project-alpha:* Docker Hub images.
- Optional: ~5GB reclaimable Docker build cache -> `docker builder prune -f`.
