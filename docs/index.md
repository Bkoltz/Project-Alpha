---
title: Project Alpha Documentation
description: Learn what Project Alpha does and how its business workflows fit together.
---

<section class="hero">
  <p class="eyebrow">Project-Alpha Documentation</p>
  <h1>Run your business,<br><span>not your paperwork.</span></h1>
  <p class="lead">Learn how to manage clients, projects, quotes, contracts, invoices, payments, expenses, and operations with Project-Alpha.</p>
  <div class="hero-actions">
    <a class="button button-primary" href="getting-started/">Get started</a>
    <a class="button button-secondary" href="workflows/">Explore workflows</a>
  </div>
  <div class="hero-points" aria-label="Project Alpha benefits">
    <span>Open source</span>
    <span>Self-hostable</span>
    <span>Built for small business</span>
  </div>

</section>

## Start Here

If you have never seen PA before, use this path:

<ol class="path-list">
  <li><div><strong><a href="getting-started/">Get oriented</a></strong><br>Understand what PA is, who it is for, and where each major area lives.</div></li>
  <li><div><strong><a href="getting-started/core-concepts.html">Learn the core concepts</a></strong><br>Clients, organizations, projects, documents, public links, payments, and scheduled jobs.</div></li>
  <li><div><strong><a href="workflows/">Follow the workflow map</a></strong><br>See how quote to contract to invoice to payment fits together.</div></li>
  <li><div><strong><a href="admin/settings.html">Configure the system</a></strong><br>Set identity, domain, email, billing, taxes, documents, notifications, and backups.</div></li>
</ol>

## What PA Helps With

<div class="card-grid">
  <div class="card"><strong>Sales Documents</strong>Quotes, contracts, invoices, terms, custom fields, discounts, deposits, and taxes.</div>
  <div class="card"><strong>Client Workflows</strong>Public links for quote approval, contract signing, invoice payment, project access, and onboarding.</div>
  <div class="card"><strong>Projects</strong>Group client work, files, documents, project invoices, links, and job codes in one place.</div>
  <div class="card"><strong>Payments</strong>Stripe payments, offline payment recording, partial payments, refunds, receipts, and reconciliation.</div>
  <div class="card"><strong>Operations</strong>Expenses, receipts, vendors, mileage, forms, backups, cron jobs, and audit reports.</div>
  <div class="card"><strong>Administration</strong>Users, roles, 2FA, app settings, email, security, deployment, and update safety.</div>
</div>

## Common Tasks

| Task | Start Here |
|---|---|
| Create a quote, contract, or invoice | [Quotes, Contracts, Invoices](workflows/documents.html) |
| Configure services, packages, Work Activities, or hourly billing | [Service Library and Work Activities](workflows/service-catalog-and-work-types.html) |
| Review time, corrections, worker statements, or payroll exports | [Workforce, Time, Billing, and Pay](workflows/workforce-time-billing-and-pay.html) |
| Send a client a secure document link | [Public Links](reference/public-links.html) |
| Invite a client to submit their information | [Client Onboarding](workflows/client-onboarding.html) |
| Set up monthly or yearly billing | [Recurring Billing](workflows/recurring-billing.html) |
| Configure Stripe | [Stripe Payments](admin/payments-stripe.html) |
| Import sales tax rates | [Tax Rates](admin/tax-rates.html) |
| Deploy PA | [Deployment](admin/deployment.html) |
| Protect and restore data | [Backups](admin/backups.html) |

## Important Boundaries

Project Alpha is operational business software. It is not accounting, legal, or tax advice. Operators are responsible for HTTPS, DNS, backups, credentials, payment processor setup, local tax rules, and compliance review.

<div class="callout">
PA is designed for a self-hosted deployment operated for one business. It has organization-aware records and permissions, but it is not currently packaged as a fully isolated hosted multi-tenant SaaS.
</div>

