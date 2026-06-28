# Two-Factor Authentication

Project Alpha supports optional time-based one-time passwords (TOTP), backup codes, and remembered-device controls.

## User Flow

1. The user signs in with email and password.
2. If 2FA is enabled and the device is not trusted, the user enters a TOTP or backup code.
3. Successful verification completes the session and may remember the device when requested.

Users manage 2FA from their account page. Setup displays an authenticator URI or QR code, verifies an initial code, and generates backup codes. Backup codes should be stored outside Project Alpha and treated like passwords.

## Main Files

- `src/controllers/auth/two_factor_setup.php`
- `src/controllers/auth/two_factor_verify.php`
- `src/controllers/auth/admin_2fa_disable.php`
- `src/views/pages/auth/two_factor_setup.php`
- `src/views/pages/auth/two_factor_verify.php`
- `src/utils/two_factor_auth.php`

The current schema is defined in `database/init.sql` and active migrations. Do not apply obsolete standalone 2FA migration instructions.

## Security Requirements

- Require HTTPS in production.
- Rate-limit login and verification attempts.
- Regenerate sessions after authentication state changes.
- Protect TOTP secrets and backup codes.
- Revoke remembered devices after a password reset or suspected compromise.
- Record administrative 2FA changes in the audit log.

Test setup, valid and invalid codes, clock drift, backup-code consumption, remembered devices, revocation, password reset, administrative disable, and throttling.
