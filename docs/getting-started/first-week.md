---
title: First Week Checklist
description: Practical setup order for a new Project Alpha installation.
---

# First Week Checklist

Use this checklist after PA is installed and reachable in a browser.

## Day 1: Identity and Access

- Change the default admin password.
- Add named user accounts.
- Enable 2FA for owner or admin accounts.
- Configure business identity in **Settings > System**.
- Set the application domain if public links or email links will be used.

## Day 2: Email and Documents

- Configure SMTP.
- Send a test email.
- Add standard terms.
- Review document defaults and custom fields.
- Create a test quote, contract, and invoice.

## Day 3: Payments

- Configure accepted payment methods.
- Add Stripe test keys and webhook secret if Stripe will be used.
- Pay a test invoice.
- Confirm receipt and invoice status behavior.

## Day 4: Clients and Projects

- Add real clients and organizations.
- Create departments where needed.
- Test client onboarding.
- Create a sample project with files and documents.

## Day 5: Operations

- Confirm backups are generated.
- Test a restore in a separate environment.
- Review cron logs.
- Configure tax rates if PA will calculate document tax.

## Before Real Client Use

Do one complete workflow using test data and a real email inbox. Then delete or archive the test records.

