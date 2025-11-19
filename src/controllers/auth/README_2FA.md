# Two-Factor Authentication (2FA) Implementation

This directory contains a complete TOTP-based Two-Factor Authentication system for Project Alpha.

## Overview

The 2FA implementation uses Time-based One-Time Passwords (TOTP) as defined in RFC 6238, compatible with popular authenticator apps like:
- Google Authenticator
- Microsoft Authenticator
- Authy
- 1Password
- Any other TOTP-compatible app

## Files Added

### Controllers
- **`two_factor_setup.php`** - Handles 2FA setup, enable/disable, and backup code regeneration
- **`two_factor_verify.php`** - Handles 2FA verification during login
- **`auth_handler.php`** - Modified to integrate 2FA into login flow

### Views
- **`src/views/pages/auth/two_factor_setup.php`** - User interface for 2FA management
- **`src/views/pages/auth/two_factor_verify.php`** - 2FA code entry during login

### Utilities
- **`src/utils/two_factor_auth.php`** - Core TOTP library with Base32 encoding/decoding

### Database
- **`database/migrations/001_2fa.sql`** - Database schema for 2FA tables

## Database Schema

### `user_2fa` Table
Stores user 2FA settings:
- `user_id` - Foreign key to users table
- `secret` - Base32-encoded TOTP secret
- `enabled` - Whether 2FA is active
- `backup_codes` - JSON array of hashed backup codes
- `enabled_at` - Timestamp when 2FA was enabled

### `login_2fa_attempts` Table
Logs 2FA verification attempts for security monitoring:
- `user_id` - User attempting verification
- `ip` - IP address of attempt
- `success` - Whether verification succeeded
- `attempted_at` - Timestamp

## Setup Instructions

### 1. Run Database Migration
```bash
mysql -u username -p database_name < database/migrations/001_2fa.sql
```

### 2. Update Routing
Add the following page routes to your router/index.php:

```php
// 2FA Setup (authenticated users only)
case '2fa-setup':
    require __DIR__ . '/src/views/pages/auth/two_factor_setup.php';
    break;

case '2fa-setup-action':
    require __DIR__ . '/src/controllers/auth/two_factor_setup.php';
    break;

// 2FA Verification (during login)
case '2fa-verify':
    require __DIR__ . '/src/views/pages/auth/two_factor_verify.php';
    break;

case '2fa-verify-action':
    require __DIR__ . '/src/controllers/auth/two_factor_verify.php';
    break;
```

### 3. Add Navigation Link
Add a link to the 2FA setup page in your user settings/account menu:

```html
<a href="/?page=2fa-setup">Two-Factor Authentication</a>
```

## User Flow

### Enabling 2FA
1. User navigates to `/?page=2fa-setup`
2. Clicks "Enable Two-Factor Authentication"
3. Scans QR code with authenticator app
4. Saves 8 backup codes securely
5. Enters verification code to complete setup

### Login with 2FA
1. User enters email and password on login page
2. If 2FA is enabled, redirected to `/?page=2fa-verify`
3. User enters 6-digit code from authenticator app
4. On success, logged in; on failure, shown error with retry option
5. Can use backup codes if authenticator unavailable

### Disabling 2FA
1. User navigates to `/?page=2fa-setup`
2. Enters password to confirm identity
3. 2FA is disabled and secret/backup codes removed

### Regenerating Backup Codes
1. User navigates to `/?page=2fa-setup`
2. Enters current 2FA code to verify
3. New backup codes generated (old ones invalidated)
4. User must save new codes

## Security Features

### Rate Limiting
- **Login attempts**: Max 15 failed login attempts per IP in 10 minutes
- **2FA verification**: Max 10 failed 2FA attempts per user in 10 minutes

### Code Verification
- TOTP codes valid for ±30 seconds (1 time window before/after)
- Backup codes hashed with SHA-256 before storage
- Each backup code can only be used once

### Session Security
- Session regenerated after successful login
- 2FA pending state stored separately from user session
- CSRF protection on all forms

## Configuration

### Customize Application Name
Edit `src/utils/two_factor_auth.php` line 116:

```php
public static function getOtpAuthUri(string $secret, string $email, string $issuer = 'Your App Name'): string
```

### Adjust Security Parameters
In `TwoFactorAuth` class methods:
- **Time period**: Default 30 seconds (RFC 6238 standard)
- **Code length**: 6 digits (RFC 6238 standard)
- **Time window**: ±1 period (±30 seconds tolerance)
- **Backup codes**: 8 codes by default

## API Reference

### TwoFactorAuth Utility Class

```php
use App\Utils\TwoFactorAuth;

// Generate new secret for user
$secret = TwoFactorAuth::generateSecret();

// Verify TOTP code
$isValid = TwoFactorAuth::verifyCode($userCode, $secret);

// Generate backup codes
$codes = TwoFactorAuth::generateBackupCodes(8);

// Get QR code URI for authenticator apps
$uri = TwoFactorAuth::getOtpAuthUri($secret, $userEmail, 'App Name');
```

## Testing

### Manual Testing Steps
1. Create a test user account
2. Enable 2FA and scan QR code with authenticator app
3. Log out and log back in
4. Verify 2FA code prompt appears
5. Test with valid code (should succeed)
6. Test with invalid code (should fail)
7. Test with backup code (should succeed and consume code)
8. Test regenerating backup codes
9. Test disabling 2FA

### QR Code Generation
The implementation uses Google Charts API for QR code generation. For production, consider:
- Self-hosted QR code library (e.g., `endroid/qr-code`)
- Server-side SVG QR code generation
- Client-side JavaScript QR code generation

## Troubleshooting

### "Time sync" issues
If codes are consistently invalid, check server time synchronization:
```bash
# Linux/Mac
sudo ntpdate pool.ntp.org

# Windows
w32tm /resync
```

### Backup codes not working
Ensure backup codes are entered without spaces or dashes (both formats accepted).

### QR code not displaying
Verify Google Charts API is accessible or implement alternative QR code generation.

## Future Enhancements

Consider adding:
- **SMS/Email 2FA** as alternative to TOTP
- **Trusted devices** (remember this device for 30 days)
- **2FA enforcement** for admin roles
- **Recovery email** for account lockout scenarios
- **Admin dashboard** to view 2FA adoption rates
- **Audit log** for 2FA events

## Security Considerations

- ⚠️ **Backup codes**: Users MUST save backup codes securely
- ⚠️ **Account recovery**: Plan for users who lose both authenticator and backup codes
- ⚠️ **Time synchronization**: Server must have accurate time (use NTP)
- ⚠️ **HTTPS required**: Always use HTTPS in production to protect secrets
- ⚠️ **Secret storage**: Secrets stored in database; ensure database is secured

## Support

For issues or questions about the 2FA implementation, refer to:
- RFC 6238: TOTP specification
- RFC 4648: Base32 encoding specification
- Authenticator app documentation for QR code format
