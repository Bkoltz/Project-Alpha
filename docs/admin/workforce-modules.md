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
2. Open **Settings > Work, Jobs & Pay > Workflow defaults**. Set the Workforce
   currency, fallback pay/billing rates, and worker time requirements in the
   **Time & Workforce** section. The business name and timezone remain under
   **Business & Branding**.
3. Open **Accounts**, create or edit a PA user, and choose the **Employee** role.
   The Employee role loads the strict self-service ACL defaults automatically.
4. Complete the employee profile, pay visibility, and assigned projects on the
   same Account form. Optional assignment and service-component compensation
   rules take precedence over broader defaults.
5. Employees use **Workforce > Time**; approvers use **Approvals**; authorized
   users use **Employee Pay**. The Workforce Overview summarizes time and pay.

Timekeeping managers can enter time for any active PA account. Client, project,
and mutable draft-invoice context is optional for managers. Employees never see
direct client or invoice rates and select only the services and Work Activities available to them.

Client billing and worker compensation resolve separately. For hourly client
billing, PA uses project override, client override, Service Activity rate, Work
Activity default, then the business fallback billing rate. Compensation uses
the most specific valid assignment or service-activity rule before Work Activity and worker/business
fallbacks. Missing required rates block the affected payable or billable step.

See [Service Library and Work Activities](../workflows/service-catalog-and-work-types.html)
for the complete integration and examples.

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
