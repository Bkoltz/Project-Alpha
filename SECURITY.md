# Security Policy

## Supported Surface

Project Alpha is a self-hosted PHP application intended to run behind an authenticated web session and a trusted deployment operator. Public document, quote, invoice, payment, and onboarding links are supported external surfaces and must fail closed on invalid or expired tokens.

Local deployment files, environment variables, Docker secrets, and mounted config volumes are operator-controlled inputs. They must not be committed with real credentials.

## Secrets

Do not commit `.env`, `config/.env`, encryption keys, database passwords, Stripe keys, SMTP credentials, or backup archives. Use environment variables, Docker secrets, or protected deployment configuration.

Rotate credentials immediately if a secret is committed, pasted into an issue, exposed in logs, or included in a shared backup.

Project Alpha has no permanent default administrator. Clean installations create the first administrator in the web setup. Docker operator recovery generates a one-time password for an existing active administrator, revokes sessions and reset tokens, and requires an immediate password change. TOTP is reset only with the explicit `--reset-totp` option and must then be enrolled again.

## Reporting

Report suspected vulnerabilities privately to the repository owner or deployment operator. Include the affected route or file, required privileges, impact, and reproduction details when safe to share.
