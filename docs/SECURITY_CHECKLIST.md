# Security Checklist

*Reflects the 2026-06-17 security hardening pass.*

## Authentication
- [x] Passwords hashed with bcrypt (cost >= 10)
- [x] Login throttling (IP: 15/10min, account: 5/15min)
- [x] Session timeout (8 hours)
- [x] Secure cookies (Secure, HttpOnly, SameSite=Strict)
- [x] 2FA (TOTP) available
- [x] Trusted device tokens revocable
- [ ] Force password reset after breach

## Authorization
- [x] Role-based access control (admin/user)
- [x] CSRF tokens on all state-changing requests
- [x] Public pages explicitly whitelisted
- [x] API keys hashed, IP-restricted
- [ ] API keys scoped (scope validation pending)

## Data Protection
- [x] Prepared statements for all SQL
- [x] Input validation on forms
- [x] Output encoding with `htmlspecialchars`
- [x] File upload validation (MIME, size)
- [ ] Database backups encrypted at rest

## Infrastructure
- [x] Security headers (CSP, HSTS, X-Frame-Options)
- [x] HTTPS only (HSTS 1 year)
- [x] Rate limiting on public endpoints (30/min/IP)
- [x] Webhook signature verification
- [x] CORS restricted to known origins (`ALLOWED_ORIGINS`)
- [x] Audit logging for sensitive actions

## Testing
- [x] Security unit tests pass (PHPUnit `tests/Security`)
- [ ] Penetration test annually
- [ ] Dependency vulnerability scan monthly (Dependabot)
