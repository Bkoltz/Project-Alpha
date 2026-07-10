---
title: Recurring Billing
description: Monthly, yearly, and other long-term billing behavior in Project Alpha.
---

# Recurring Billing

Recurring billing is driven by long-term contracts.

## Billing Schedule

A per-invoice long-term contract contains one or more recurring service schedules. Each service stores its own amount, interval, effective dates, approval state, and next invoice date. This supports combinations such as annual website hosting and monthly advertising management under one agreement.

Services due on the same date are combined into one invoice with separate line items. Services on different dates generate independently. Editing a service changes only future invoices; generated invoices remain historical records.

## Amendments and Add-ons

Use **Recurring Services & Amendments** on the long-term contract details page to add or change a service.

- Unapproved services remain pending and are excluded from invoice generation.
- Approval may be recorded directly or evidenced by an uploaded signed addendum PDF.
- Every addition, edit, approval, pause, resume, end, and proration is recorded in amendment history.
- An optional explicit prorated subtotal can generate a one-time long-term invoice without changing the full recurring amount.

## First Invoice

When a long-term contract becomes active and the first invoice is already due, PA attempts to generate it immediately. This avoids waiting until the next scheduled cron run or the next yearly date.

When `invoice_auto_email_on_generate` is enabled, each generated direct-collection invoice is finalized and emailed once with a public payment link. Delivery uses an idempotent `on_generate` notification record, so a cron retry does not send the same invoice twice.

## Catch-Up Behavior

The cron job can generate missed invoices when PA has been offline. It uses an idempotency guard and a maximum catch-up pass count to avoid duplicate or runaway generation.

## Contract and Invoice Status

The contract lifecycle and each invoice lifecycle are independent:

- Paying a monthly or yearly invoice marks that invoice paid and leaves the long-term contract active.
- Pausing a contract stops scheduled generation while preserving existing invoice history.
- A long-term contract completes only when its configured end or fixed-total schedule is reached, or when an operator explicitly terminates it.
- Open-ended per-invoice contracts continue until explicitly terminated.

The **Recurring Billing** page shows both the active schedules and a filterable history of every generated recurring invoice, including paid invoices.

## Manual Recovery

If a long-term contract is active but an invoice was not generated when expected, use the long-term contract details page to review the schedule and generate or send the needed invoice.

