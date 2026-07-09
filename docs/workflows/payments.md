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

## Receipts

PA can send branded receipts for ordinary invoice payments.

