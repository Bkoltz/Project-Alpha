# AutoPay Beta Foundation

> **Unavailable and not approved for production use.**

Project Alpha supports client-initiated, one-time invoice payments. It does not currently expose or run AutoPay.

The repository contains dormant database tables and internal interfaces so a future implementation can be designed without reusing the unsafe legacy client flags. There are no AutoPay routes, pages, settings, emails, scheduled jobs, or enabled Stripe off-session operations.

## Runtime Guard

`AUTOPAY_BETA_ENABLED` defaults to `false` and is read only from the environment. Even when explicitly set to `true`, the internal beta guard only opens in `development` or `test`. Production always fails closed.

The former `auto_charge_recurring.php` file is retained for historical development context, is absent from the installed crontab, and exits before loading payment logic unless the development-only guard passes.

## Before Any Future Release

A production implementation requires independent legal and security review. At minimum, it must include:

- Separate, explicit consent to save a payment method and to make future charges
- Versioned, retainable authorization terms describing scope, timing, frequency, and amount calculation
- Email confirmation for broad or variable scopes
- Advance notices for variable charges where required
- Immediate online revocation plus an accessible offline cancellation method
- One-time, rate-limited recovery tokens that expose only payment preferences
- Stripe SetupIntent or equivalent mandate handling and correct merchant-initiated transaction flags
- Idempotent attempts, authentication recovery, receipts, refunds, disputes, and reconciliation
- Consumer/business classification with consumer-safe behavior for unknown clients

Reference material:

- [Stripe SetupIntents and future payments](https://docs.stripe.com/payments/setup-intents)
- [CFPB Regulation E, 12 CFR 1005.10](https://www.consumerfinance.gov/rules-policy/regulations/1005/10/)
- Applicable state automatic-renewal, privacy, breach-notification, surcharge, and record-retention requirements

These references are implementation context, not legal advice. Operators remain responsible for their own agreements and compliance review.
