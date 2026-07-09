---
title: Recurring Billing
description: Monthly, yearly, and other long-term billing behavior in Project Alpha.
---

# Recurring Billing

Recurring billing is driven by long-term contracts.

## Billing Schedule

A long-term contract stores the interval count, interval unit, start date, next invoice date, and pricing rules. The interval can be changed later, such as moving a client from monthly billing to yearly billing.

## First Invoice

When a long-term contract becomes active and the first invoice is already due, PA attempts to generate it immediately. This avoids waiting until the next scheduled cron run or the next yearly date.

## Catch-Up Behavior

The cron job can generate missed invoices when PA has been offline. It uses an idempotency guard and a maximum catch-up pass count to avoid duplicate or runaway generation.

## Manual Recovery

If a long-term contract is active but an invoice was not generated when expected, use the long-term contract details page to review the schedule and generate or send the needed invoice.

