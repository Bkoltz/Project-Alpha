---
title: Security
description: Security controls and operator responsibilities.
---

# Security

PA includes application-level security controls, but operators still control the hosting environment.

## Built-In Controls

- CSRF validation
- Prepared database access
- Rate limiting on sensitive public routes
- Optional TOTP 2FA
- Roles and permission checks
- Audit logging
- Security headers
- Tokenized public links
- Webhook signature verification when Stripe secret is configured
- Encrypted storage for configured secrets
- Versioned sessions that can be revoked by administrator recovery
- No permanent or default administrator credential
- Final-active-administrator protection

## Operator Responsibilities

- Use HTTPS.
- Use unique passwords.
- Protect the database and config volumes.
- Protect the encryption key.
- Keep MySQL private.
- Rotate credentials when staff or infrastructure changes.
- Review logs without sharing customer data.
- Test backups and restores.

## Account Recovery

Use normal email reset when SMTP is configured. Public reset requests always return a generic response so they do not reveal whether an email exists. Codes expire after five minutes, are hashed in storage, are single-use, and are revoked after successful login.

When email or TOTP is unavailable, use the operator-only commands documented in [Deployment](deployment.html). Password recovery preserves TOTP unless the operator explicitly supplies `--reset-totp`. Review `admin.recovery_password_issued` and `admin.recovery_totp_reset` events in the security audit trail after any recovery.

## Reporting

Report vulnerabilities privately using the process in [Security Policy](../archive/SECURITY.md).

