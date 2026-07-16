# Sign-in security

Project Alpha supports password sign-in, optional authenticator-app TOTP, trusted devices after password/TOTP verification, and optional passwordless WebAuthn passkeys.

## TOTP

Enrollment renders a local SVG QR code from the `otpauth://` URI and verifies an initial six-digit code before enabling TOTP. The manual secret is available only in a collapsed fallback. No secret is sent to an external QR service.

Application backup codes were retired in schema 46. Recovery uses another passkey, TOTP, an authorized administrator reset, or the audited Docker administrator-recovery command.

## Passkeys

Set Settings → System → Application Domain to the public hostname users open in their browser. PA derives its HTTPS WebAuthn origin from that hostname, including when a reverse proxy serves PA under a path. To override it, set `WEBAUTHN_ORIGIN` to the exact canonical HTTPS origin (for example `https://pa.example.com`, without a path). `WEBAUTHN_RP_ID` is optional and defaults to that origin's hostname. HTTP is accepted only for localhost development. Request `Host` and forwarded headers are never used to choose the relying party.

Passkeys are discoverable credentials with user verification required. Users can register multiple named credentials. Registration, rename, and revocation require current-password confirmation. Challenges are hashed, session-bound, expire after five minutes, and are consumed once.

## Operational notes

- Run migration 0046 before enabling passkey routes.
- Keep `APP_ENCRYPTION_KEY` and session configuration secure.
- Use HTTPS in production.
- Review `auth.passkey_*`, `auth.totp_admin_reset`, and recovery audit events.
- A successful passkey assertion satisfies both primary authentication and MFA; a password login still requires TOTP when enabled.
