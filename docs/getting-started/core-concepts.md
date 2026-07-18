---
title: Core Concepts
description: The records and ideas used throughout Project Alpha.
---

# Core Concepts

## Clients and Organizations

A client is the person or business that receives documents and payments. A client can belong to an organization. Organizations can have departments, shared files, contacts, tax exemption documents, and link behavior that applies across multiple clients.

## Projects and Job Codes

A project groups related work. Projects can include clients, organizations, files, notes, quotes, contracts, invoices, and project invoices.

A job code is copied across related documents so a quote, contract, and invoice can be traced together even outside a project.

## Document Families

PA has three document families:

| Family | Best For | Invoice Behavior |
|---|---|---|
| Regular | One-time work | Usually one invoice for the contract |
| Long-term | Monthly, yearly, or recurring work | Scheduled invoices from an active contract |
| On-demand | As-needed work under an agreement | Invoices generated manually from the active contract |

## Services and Work Activities

The Service Library stores reusable client-facing services, fees, and packages. Work Activities classify what workers did on time entries. Each service can connect to one or more reusable activities while client billing and worker compensation remain independent calculations.

See [Service Library and Work Activities](../workflows/service-catalog-and-work-types.html) for the settings, rate precedence, and hourly billing workflow.

## Public Links

Public links let a client interact without a full client portal account. Links can allow quote approval, contract upload/signing, invoice payment, project access, and document viewing. PA tracks whether a document link is accessible, redirected, or unavailable.

## Payments

PA supports Stripe and offline payment recording. Invoices can be unpaid, partially paid, or paid. Partially paid invoices remain accessible because there is still a balance due.

## Scheduled Jobs

The cron service performs background work such as recurring invoice generation, invoice reminders, backups, audit schedules, public-link expiration, Stripe reconciliation, and merchant-rate synchronization.

