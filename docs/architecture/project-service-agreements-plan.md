# Project Service Agreements and Dynamic Project Billing

## Status

Approved implementation plan. This document is architecture only; the Service Agreement feature has not been implemented.

## Summary

Add Project-owned Service Agreements that define scope, pricing, and terms while leaving invoice timing under the Project's billing configuration.

- One active Service Agreement per Project; older agreements remain immutable history.
- No native e-signing in the initial release. Generate or download the agreement and upload a signed copy.
- Agreements authorize both per-invoice and consolidated billing, so changing the Project mode does not require an amendment.
- Actual work, rather than anticipated work, creates invoice charges.
- Finalized invoices retain their original pricing and billing assignment permanently.

## Service Agreements and pricing

- Add project-only Service Agreements with draft, sent, signed-copy-uploaded, active, superseded, completed, terminated, and expired states.
- Require staff activation after a valid signed copy is uploaded. Support both client-link and staff uploads without requiring outgoing email.
- Add immutable agreement revisions containing scope, terms, schedule, pricing-model version, eligibility, dates, and initial Project billing mode.
- Add reusable fixed and progressive pricing models, an optional installation default, configurable duration tiers, and service eligibility.
- Calculate progressive tiers from the Agreement's pricing-effective date using each work item's performed date. Paused-time behavior is configurable per Agreement.
- Agreement discounts take exclusive precedence over inherited pricing adjustments. Staff may explicitly skip or override them with an audit reason; discounts never stack automatically.
- Early termination produces a review item showing commitment, elapsed duration, eligible charges, and discounts granted. It never creates or charges an adjustment automatically.

## Actual work and invoices

- Add a Project Billable Work ledger for manual charges and explicitly imported operations, tasks, time, mileage, or other sources.
- Each work item records its performed date, service snapshot, quantity, base rate, Agreement revision, pricing tier, eligibility, discount calculation, and billing-policy segment.
- Completing a task or operation does not automatically bill it. Staff must mark work ready and generate an invoice.
- Claim work transactionally so repeated submissions or concurrent jobs cannot invoice it twice.
- Per-invoice mode creates an ordinary direct invoice that can be reviewed and sent.
- Monthly mode creates underlying aggregate child invoices that are not individually emailed, aged, or paid. The existing Project statement consolidates them and remains the payment authority.
- Project statements display the child invoices' already-calculated totals and never apply Agreement pricing a second time.

## Dynamic billing-mode transitions

Replace the raw billing-mode toggle with a transition workflow and audit history.

### Monthly to per invoice

The default is **Finish current billing period**:

- Keep work through the current calendar-period boundary assigned to monthly billing.
- At period close, generate the normal Project statement and respect the Project's automatic-email setting.
- Activate per-invoice billing for work performed after the boundary.

Also offer **Close and send now**:

- Preview pending invoices, total, cutoff date, and recipients.
- Generate and finalize a shortened Project statement through the cutoff.
- Queue its email through the existing durable delivery workflow.
- Activate per-invoice billing after the statement is committed. An email retry does not cause invoices to revert or duplicate.
- If nothing is pending, switch immediately without creating an empty statement.

### Per invoice to monthly

- Begin monthly billing immediately for new work.
- Previously finalized direct invoices remain independent and are never swept into a statement.
- New work performed from the effective date through month-end enters a partial first monthly period.
- Subsequent periods return to normal calendar-month boundaries.

Every underlying invoice receives an immutable billing assignment and period when finalized. Existing drafts display a warning if the Project mode changed and require confirmation before finalization. Backdated work uses its performed date and the policy segment effective on that date.

Persist transition records containing the old and new modes, selected transition strategy, cutoff and effective dates, affected statement, initiating user, timestamps, and outcome. Repeated submissions, cron runs, and retries must be idempotent.

## Documents, settings, and Project UI

- Add Service Agreement Terms under Settings -> Terms & Validity.
- Use one shared rich-text editor for all terms and Service Agreement scope. Support headings, formatting, lists, links, tables, constrained sizes and colors, and validated uploaded images.
- Sanitize content server-side with a strict allowlist. Reject scripts, iframes, arbitrary CSS, remote media, and unsafe URLs.
- Snapshot rendered terms into every Agreement revision so settings changes cannot alter signed documents.
- Add Service Agreements to shared PDF, revision, public-link, delivery, and signed-upload infrastructure.
- Generated agreements include printable signature lines but no browser-based signature controls.
- Add a Project Service Agreement card showing status, pricing model, current and next tier, dates, schedule, billing mode, and unbilled work.
- Make Project list rows clickable while preserving their independent action controls.
- Record before and after values for Project date and billing changes in activity history.

## Migration and compatibility

- Use additive migrations and gate the new Service Agreement, pricing-model, and Billable Work interfaces behind an installation feature setting.
- Preserve `projects.invoice_billing_period` as the current effective mode while adding transition history and immutable invoice billing-period assignments.
- Do not rewrite finalized invoices, payments, discounts, statements, contracts, or existing signature behavior.
- Stamp linked aggregate invoices from their existing Project statement period. Stamp unlinked aggregate invoices using their current calendar period without changing financial values.
- Preserve existing LTDS Operations integration and access behavior; operational records enter Billable Work only through an explicit user action.
- Convert legacy plain-text terms to sanitized paragraphs without changing displayed wording.

## Test and acceptance plan

- Verify Agreement project ownership, one-active-agreement enforcement, revision immutability, signed-copy validation, staff activation, and authorization boundaries.
- Test fixed and progressive tier boundaries, all duration units, pauses, backdated work, calendar anniversaries, end-of-month behavior, eligibility, overrides, and exact minor-unit rounding.
- Cover monthly period completion, immediate shortened close, no pending invoices, failed or retried email, immediate partial monthly entry, existing direct invoices, existing drafts, backdated work, repeated requests, and concurrent cron execution.
- Confirm aggregate children are never individually emailed, marked overdue, exposed for payment, or repriced by the Project statement.
- Test Billable Work claiming and invoice generation for duplicate prevention and transaction rollback.
- Test HTML sanitization, unsafe links and media, PDF rendering, uploaded signed-copy scanning, tenancy, CSRF, and public-link access.
- Run relevant PHP tests, frontend tests, lint and type checks, database migration tests, and the production build.

## Defaults and exclusions

- Agreement terms authorize both supported Project billing modes.
- Monthly-to-per-invoice defaults to finishing the current period.
- Per-invoice-to-monthly begins immediately for new work.
- Billing periods are calendar-month based unless Project Alpha later adds configurable billing anchors.
- Native e-signing, automatic termination charges, and automatic billing from completed tasks remain out of scope.
