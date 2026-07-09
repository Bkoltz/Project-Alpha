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

## Operator Responsibilities

- Use HTTPS.
- Use unique passwords.
- Protect the database and config volumes.
- Protect the encryption key.
- Keep MySQL private.
- Rotate credentials when staff or infrastructure changes.
- Review logs without sharing customer data.
- Test backups and restores.

## Reporting

Report vulnerabilities privately using the process in [Security Policy](../archive/SECURITY.md).

