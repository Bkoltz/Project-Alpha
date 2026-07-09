---
title: Backups and Recovery
description: How to protect and restore Project Alpha data.
---

# Backups and Recovery

A usable PA backup strategy protects more than the database.

## What to Back Up

- MySQL data
- Uploads
- Configuration volume
- Encryption key
- Backup volume
- Deployment configuration

## Cron Backups

The cron service is responsible for scheduled database backups. Confirm backup files are being created before relying on the installation.

## Recovery Rule

A backup has not been proven until it has been restored into a separate environment.

## Key Custody

Encrypted settings and secrets require the same encryption key used by the running installation. Protect the key separately from the database dump.

## Restore Practice

Restore to staging or another isolated environment, then verify login, documents, uploads, settings, and scheduled jobs.

