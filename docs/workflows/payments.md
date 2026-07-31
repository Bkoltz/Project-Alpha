---
title: Payments
description: How Project Alpha records online and offline invoice payments.
---

# Payments

PA tracks invoice payment status and supports both online and offline payment records.

## Online Payments

Stripe can be used for invoice and project invoice payments. PA creates payment sessions or payment intents, records successful payments, handles partial payments, and updates invoice status.

## Offline Payments

Cash, check, bank transfer, or other configured methods can be recorded manually. Offline payments are included in invoice status and financial reporting.

## Partial Payments

An invoice can be partially paid. PA keeps the invoice link available while a balance remains due.

## Paid in Full

When an invoice is paid in full, PA can redirect the public link to a status page and revoke payment access so the client sees that the invoice has already been paid.

A general-recipient invoice is the narrow exception: payment access closes immediately, but the same public invoice and PDF remain available as a paid receipt for seven days from the recorded `paid_at` time. The link expires at that boundary and is not extended by later views or duplicate webhook delivery.

If a payment is refunded and the invoice is paid again, PA records a new `paid_at` and recalculates one seven-day receipt window from that new payment time. It does not retain the first payment's deadline.

## Receipts

PA can send branded receipts for ordinary invoice payments.

PA does not create or email the separate branded payment receipt for a general-recipient invoice, because that receipt is tied to the private internal accounting client. Its paid public invoice is the only external receipt.

