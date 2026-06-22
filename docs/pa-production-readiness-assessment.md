# Project Alpha — Production Readiness Assessment

> Generated 2026-06-21. Combined codebase audit + 2026 web research.
> This is a technical assessment, NOT legal advice. Legal documents must be reviewed by a licensed Wisconsin attorney.

---

## Current State Summary

PA is a PHP 8.3 / MySQL 8 / Docker SaaS for quote/contract/invoice management with Stripe payments. The security fundamentals are solid for an MVP — the gaps are in infrastructure, compliance artifacts, multi-tenancy, and observability.

### What PA already has (solid foundation)

- CSRF protection on all POST endpoints (synchronizer tokens)
- Security headers: CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy
- 2FA/TOTP authentication with backup codes
- Password policy enforcement (min length, complexity)
- Rate limiting on public endpoints (login, password reset, public links)
- Prepared statements everywhere (~500+ prepare() calls, only ~15 raw queries)
- bcrypt password hashing
- Secure session cookies (HttpOnly, SameSite=Strict, Secure when HTTPS)
- Audit logging (system_audit table + Monolog JSON rotating logs)
- Legal docs: ToS, Privacy Policy, AUP, DMCA, Data Retention Policy (Wisconsin jurisdiction)
- ToS acceptance gate (clickwrap)
- GDPR/CCPA: data export + account deletion
- Stripe PCI-compliant payment flow (Stripe Checkout, no card data stored)
- Encrypted DB backups (optional GPG)
- ClamAV file upload scanning (optional)
- Docker Compose deployment (no .env required, 3 passwords)
- CI pipeline (GitHub Actions: tests, gitleaks, Docker build)
- 4 PHPUnit security tests (CSRF, password policy, rate limit, webhook signature)
- Configurable backup retention + schedule from UI
- Separate backup volume (mountable to custom path)

---

## Phase 1: MUST-DO before any real paying customer

### Security

1. **Fix 10 dependency vulnerabilities** (1 critical, 3 high, 3 moderate, 3 low)
   - Run `composer audit` in CI
   - Update or patch all flagged packages
   - Add Dependabot/Renovate auto-PRs for security patches

2. **Containers run as root** — neither Dockerfile has a USER directive
   - Add `USER www-data` to web Dockerfile (after Apache config)
   - Create non-root user in cron Dockerfile
   - Test that file permissions still work (uploads, backups, config writes)

3. **MySQL least-privilege user** — app uses root password in some connections
   - Create a dedicated `pa_app` user with SELECT/INSERT/UPDATE/DELETE on project_alpha only
   - Remove MYSQL_ROOT_PASSWORD from web/cron environment
   - Root should only be used for init/migrations in start.sh

4. **CSP allows 'unsafe-inline' for scripts** — weakens XSS protection
   - Move all inline `<script>` blocks to external .js files
   - Remove `'unsafe-inline'` from `script-src` in security_headers.php
   - This is tedious but critical for production XSS defense

5. **CORS is permissive** — reflects Origin header back for API endpoints
   - Restrict to known origins or disable entirely (same-origin only)
   - Remove wildcard Origin reflection

6. **No HTTPS enforcement** — app doesn't redirect HTTP to HTTPS
   - Add a redirect in index.php when `$_SERVER['HTTPS']` is off
   - Or enforce at the reverse proxy layer (preferred)

7. **Switch to Argon2id password hashing** (2026 best practice)
   - Change `password_hash($pw, PASSWORD_DEFAULT)` to `PASSWORD_ARGON2ID`
   - Backward compatible — bcrypt hashes still verify, rehash on next login

8. **Add breached-password check** (Have I Been Pwned API)
   - Check passwords against HIBP k-anonymity API on create/reset
   - Reject passwords found in known breaches

### Legal & Compliance

9. **Attorney review of all legal docs** — ToS, Privacy Policy, AUP, DMCA, Data Retention
   - Current docs are AI-generated templates with Wisconsin jurisdiction
   - Must be reviewed by a licensed Wisconsin attorney before going live
   - Budget $500-2000 for this

10. **Electronic signature compliance (ESIGN/UETA)**
    - PA has contract signing but legal docs don't reference ESIGN Act or Wisconsin UETA (Wis. Stat. § 137.25)
    - Must: capture signer consent to electronic records, log IP + timestamp + user agent, provide audit trail, ensure document integrity (hash)
    - Add a clause to the signing UI: "I consent to electronic signatures and records under ESIGN/UETA"

11. **Data Processing Agreement (DPA)** — required for any EU customers
    - Create a DPA template (you = processor, customer = controller)
    - Publish subprocessor list: Stripe, hosting provider, Cloudflare, email provider
    - No EU customers yet = not immediately required, but needed before any EU sale

12. **Data breach notification procedure** — Wisconsin Wis. Stat. § 134.98 requires notification
    - Document an incident response plan
    - Define what constitutes a breach, who to notify, timeline (45 days under WI law)
    - GDPR requires 72 hours if EU users are affected

### Features

13. **Set up SMTP for transactional emails** — password reset, email verification, invoice notifications
    - PA has PHPMailer integration but SMTP may not be configured
    - Without SMTP, password reset doesn't work (critical for production)
    - Use a transactional email service (SendGrid, Postmark, Amazon SES)

14. **Email verification for new accounts**
    - Generate a signed token, email a verification link
    - Block login until email is verified
    - Required for fraud prevention and legal account ownership

15. **Backup restore testing** — backups that can't be restored are just files
    - Add a weekly cron job that restores the latest backup to a temp DB and verifies table count
    - Alert if restore fails

### Infrastructure

16. **Reverse proxy with SSL/TLS termination**
    - Add Caddy or nginx in front of PA
    - Auto-renew Let's Encrypt certificates
    - Force HTTPS redirect at proxy level
    - TLS 1.2+ only, disable 1.0/1.1

17. **Application error tracking** — no Sentry/Bugsnag equivalent
    - Set up Sentry (free tier) or self-hosted GlitchTip
    - Scrub PII from error reports
    - Alert on new errors

18. **Dedicated /healthz endpoint for PA** (not the dashboard's)
    - Check: DB connectivity, disk space, cron job last-run timestamp
    - Return JSON status for Docker healthcheck and uptime monitoring

---

## Phase 2: Before scaling beyond 1 customer

### Security

19. **Multi-tenant isolation** — THE biggest blocker for hosted SaaS
    - Currently hardcoded to org_id=1
    - Every SQL query returning user data needs WHERE organization_id = ?
    - Every controller needs to verify the requesting user's org matches the resource's org
    - Add automated tests that try to access other orgs' data (adversarial testing)
    - Consider PostgreSQL with Row Level Security as a long-term upgrade

20. **Self-service signup with protections**
    - Public registration form with captcha (hCaptcha or Cloudflare Turnstile)
    - Email verification (Phase 1 item)
    - Rate limiting on signup endpoint
    - Automatic organization creation per signup

21. **Container image scanning in CI**
    - Add Trivy or Grype to GitHub Actions
    - Fail builds on critical/high CVEs
    - Scan both web and cron images

22. **Session hardening**
    - Regenerate session_id on login, password change, privilege elevation
    - Bind session to IP fingerprint or User-Agent hash
    - Enforce server-side session timeout (not just cookie expiry)
    - Consider Redis for session storage (not filesystem in containers)

### Infrastructure

23. **Staging environment**
    - Add docker-compose.staging.yml to the repo
    - Mirror production config but on different ports/volumes
    - Deploy to staging before every production release

24. **Zero-downtime deploy strategy**
    - Current: `docker compose up -d --build` causes downtime
    - Options: blue/green (two compose stacks, switch proxy), or rolling with multiple web replicas
    - Database migrations must be backward-compatible

25. **Centralized log management**
    - Ship container logs to a central system (Grafana Loki, CloudWatch, or Datadog)
    - Never log secrets, full card numbers, or unnecessary PII
    - Set retention aligned to compliance requirements

26. **Uptime monitoring + alerting**
    - Monitor PA's /healthz endpoint from an external service
    - Alert on: DB down, cron job failures, Stripe webhook failures, disk full, backup failures
    - Use UptimeRobot (free) or Better Stack

---

## Phase 3: Enterprise readiness

### Legal & Compliance

27. **SOC 2 Type I readiness**
    - Document security policies, access controls, incident response
    - Implement change management process
    - Vendor security assessment process
    - Budget $3,000-15,000 for a SOC 2 audit

28. **GDPR/CCPA rights-handling workflow**
    - Formal process for data subject access requests (DSAR)
    - Response within 30 days (GDPR) / 45 days (CCPA)
    - Track and log all requests
    - Verify requester identity before disclosing/deleting

29. **20+ US state privacy laws** (2026 landscape)
    - Not just California — VA, CO, CT, UT, OR, MT, TX, DE, IA, TN, IN, NE, NJ, NH, MN, MD, KY, RI, FL
    - Need a unified privacy framework, not one-off state compliance
    - Right to know, delete, correct, opt-out of sale/sharing

30. **Wisconsin sales tax for SaaS**
    - WI taxes SaaS subscriptions, economic nexus threshold $100k gross sales
    - If selling to multiple states, need multi-state tax collection
    - Use TaxJar or Avalara for automated sales tax

### Code Quality

31. **Comprehensive test suite**
    - Unit tests: invoice calculations, tax computation, contract lifecycle, payment flow
    - Integration tests: against real MySQL, Stripe test mode
    - E2E tests: Playwright or Cypress for signup -> payment -> invoice critical path
    - Target meaningful coverage, not 100% vanity

32. **Static analysis in CI**
    - PHPStan or Psalm for type checking
    - Semgrep for security rules
    - Fail CI on level 5+ errors

33. **API documentation**
    - OpenAPI/Swagger spec for all API endpoints
    - Document webhook payloads and signatures
    - Version the API (/api/v1/)

34. **SBOM generation**
    - Generate CycloneDX or SPDX SBOM for every release
    - Required for enterprise/government customers

35. **Vulnerability disclosure policy (VDP)**
    - Publish security@ contact or VDP page
    - Define scope, response timeline, safe harbor
    - Optional: bug bounty program

### Features

36. **RBAC (Role-Based Access Control)**
    - Roles: Owner, Admin, Manager, Viewer, Accountant
    - Least privilege — separate billing/admin roles
    - Per-feature permissions, not just admin/user

37. **PWA packaging**
    - UI is responsive but not a PWA
    - Add manifest.json + service worker for offline capability
    - Enables "Add to Home Screen" on mobile

38. **Advanced reporting**
    - P&L, balance sheet, custom chart builder
    - Mileage IRS form auto-fill
    - Export to QuickBooks/QBO format

---

## Brutally Honest Verdict

**For self-hosted / single-customer deployment:** PA is about 70% ready. The main gaps are root containers, dependency vulnerabilities, SMTP setup, and attorney-reviewed legal docs. These are fixable in 1-2 weeks.

**For hosted multi-tenant SaaS:** PA is about 50% ready. The multi-tenant isolation gap is the single biggest blocker — it's not a feature you add, it's an architectural property that must be verified on every query. The compliance artifacts (DPA, subprocessor list, ESIGN compliance) are also needed. This is 1-3 months of work depending on how deep you go.

**For enterprise/government sales:** PA is 30% ready. SOC 2, SBOM, comprehensive testing, API docs, VDP — all missing. This is 3-6 months.

The good news: the security fundamentals (CSRF, 2FA, prepared statements, security headers, audit logging) are already in place. Most MVPs don't have these. The bad news: multi-tenancy, observability, and compliance artifacts are the unglamorous work that kills startups when they try to land their first enterprise deal.