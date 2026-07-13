---
title: Getting Started
description: A first-time path through Project Alpha.
---

# Getting Started

Project Alpha brings the back-office pieces of a service business into one self-hosted web app. The best way to learn it is to understand the records PA manages, then walk through one complete client workflow.

On a clean installation, the first browser visit displays the administrator setup form. Create your own administrator email, username, and password. There is no default login and no administrator password in Docker Compose.

## The Short Version

1. Add or import clients.
2. Create a quote, contract, or invoice.
3. Send the client a public link when they need to approve, sign, upload, or pay.
4. Track the work through projects and job codes when the engagement has more than one document.
5. Record online or offline payments.
6. Let cron jobs handle recurring invoices, reminders, backups, link expiration, and scheduled reports.

## Main Areas

| Area | What It Does |
|---|---|
| Home | Revenue, document status, recent work, and operational summaries |
| Clients | People or businesses you work with |
| Organizations | Shared client groups, departments, and organization-level files |
| Quotes | Proposed work and pricing |
| Contracts | Signed agreement and billing rules |
| Invoices | Amounts due, payment links, status, and receipts |
| Projects | A work container for documents, files, clients, and project billing |
| Workforce | Employee accounts, employment status, pay rates, and project assignments |
| Time and Approvals | Timers, breaks, manual time, review, corrections, and voids |
| Employee Pay | Pay accrual snapshots created from approved payable time |
| Financial | Expenses, receipts, mileage, vendors, forms, and reports |
| Settings | Identity, domain, email, billing, documents, links, taxes, backups, security, and notifications |

## A Good First Demo

Create a test client, make a regular quote, approve it, sign the created contract, finalize the invoice, and record a payment. That single path touches most PA concepts without requiring Stripe or live email.

Next: [Core Concepts](core-concepts.html).

