# Security Verification Checklist

Last reviewed against the repository: 2026-06-28

This is an engineering checklist, not a compliance certification.

## Application Controls

- [x] Password hashing and password policy
- [x] IP and per-account login throttling
- [x] Session cookie security settings
- [x] Optional TOTP 2FA and backup codes
- [x] CSRF validation for state-changing browser requests
- [x] Role permissions and per-user overrides
- [x] Organization and record ownership checks
- [x] Public-link expiry and revocation
- [x] Upload size/type validation with generated filenames
- [x] Stripe webhook signature validation when the secret is configured
- [x] Audit and structured application logging

## Deployment Controls

- [ ] Unique production and staging credentials
- [ ] HTTPS enforced by the external proxy
- [ ] MySQL unavailable from untrusted networks
- [ ] Configuration encryption key backed up securely
- [ ] Database and upload backups restored in a test environment
- [ ] Stripe live keys used only in production
- [ ] SMTP and OAuth credentials rotated on exposure
- [ ] Container and dependency updates reviewed regularly
- [ ] Production logs excluded from source control
- [ ] Secret scanning and push protection enabled in GitHub

## Release Review

- [ ] New routes have explicit authentication, ACL, and CSRF decisions
- [ ] New uploads use the shared validator
- [ ] New webhook behavior is idempotent and rejects invalid signatures
- [ ] New database fields have safe upgrade migrations
- [ ] Error messages do not expose secrets or internal paths
- [ ] Public issues and screenshots contain no customer data
- [ ] Security-sensitive changes have focused regression tests

Report vulnerabilities through [SECURITY.md](SECURITY.md).
