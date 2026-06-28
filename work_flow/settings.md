# Settings Overview

Configure Project Alpha before sending documents or accepting payments.

| Area | Examples |
|---|---|
| System | Organization identity, public application URL, timezone, sender profile |
| Billing | Stripe credentials, net terms, payment methods, surcharge options |
| Email | SMTP server, encrypted password, sender name and address |
| Terms | Document terms and validity period |
| Documents | Custom fields, templates, quote auto-creation behavior |
| Notifications | Cron enablement, due reminders, overdue reminders, invoice-on-generation email |
| Permissions | Roles, permission assignments, and per-user overrides |
| Taxes | Tax rates and jurisdiction imports |
| Links | Public-link lifetime and external storage integrations |

Credentials saved through supported settings are encrypted before database storage. The application encryption key is held in the persistent configuration volume; protect and back it up separately.

Use test credentials and test emails while validating a new installation.
