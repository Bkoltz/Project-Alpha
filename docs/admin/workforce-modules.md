---
title: Workforce Modules
description: Configure Project Alpha's built-in workforce, timekeeping, approvals, and employee-pay modules.
---

# Workforce Modules

Workforce, Timekeeping, Approvals, and Employee Pay run directly inside Project
Alpha. They share PA's database, login, projects, UI, deployment, and backup.
There is no second application, connection, synchronization service, or
compatibility API.

## Module ownership

- PA users and TOTP are the only login identities.
- Employees are PA users with the `employee` role and strict default ACLs.
- PA projects are authoritative; assignments control which projects employees select.
- Workforce owns timers, breaks, manual time, revisions, approval snapshots,
  and employee-pay accruals.
- PA billing consumes immutable approved snapshots internally. Corrections and
  voids append billing reversals instead of rewriting approved time.

## Initial setup

1. Sign in as an administrator. PA recommends TOTP, but the reminder may be dismissed.
2. Open **Workforce**.
3. Set the timezone, ISO currency, default pay rate, and default billing rate.
4. Create each employee. Employees receive PA logins and must replace their
   temporary password on first sign-in.
5. Assign projects and optional employee/project pay-rate overrides.
6. Employees use `/time`; approvers use `/approvals`; authorized users use `/pay`.

Pay-rate precedence is assignment override, employee rate, then business
default. Missing pay rates block payable approvals; missing billing rates block
billable approvals.

## Security boundaries

The employee role grants personal time, assigned projects, profile access, and
permitted personal pay visibility. It does not grant billing, payments,
financial administration, approvals, workforce administration, user management,
settings, or API-key access.

Privileged users receive a dismissible TOTP recommendation. Sessions use hashed server-side identifiers,
expire after 15 idle minutes, and have a seven-day absolute maximum.

## Corrections and audit

Approval freezes employee, project, revision, duration, rates, amount, and
currency. Rejected entries can be edited and resubmitted. Approved corrections
store the old revision and start a new approval cycle. Voids preserve the
snapshot and append explicit pay and billing reversals. All module mutations
write to PA's system audit trail.

## Operations

The Compose stack is `db`, `migrate`, `web`, `worker`, and `cron`. Backups cover
the one PA database and shared configuration/upload volumes. No module-specific
recovery step is required.
