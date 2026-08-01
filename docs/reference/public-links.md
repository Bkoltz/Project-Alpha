---
title: Public Links
description: How Project Alpha public links work.
---

# Public Links

Public links let clients interact with PA without a login.

## Document Links

Document links can allow:

- Quote approval or denial
- Contract signed upload
- Invoice viewing and payment
- Project invoice viewing and payment

After a terminal action, links can remain available briefly as a status page. For example, a paid invoice can show that the document has already been paid.

## Partially Paid Invoices

Partially paid invoices remain available because the client still has a balance due.

## General-Recipient Invoice Receipts

A finalized general-recipient invoice gets a deliberately created, invoice-specific public link. After full payment, Checkout is unavailable and the link becomes a non-payable receipt. Its HTML view and PDF remain available until seven days after the invoice's recorded payment time, then both expire. Ordinary paid invoice links keep their existing redirected terminal behavior.

## Project Links

Project links are long-term. They can be password-protected and controlled with project ACL settings.

## PA User Visibility

Document detail pages show whether the public link is accessible, redirected, or not accessible.

