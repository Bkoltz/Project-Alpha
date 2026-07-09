---
title: Docs Site Setup
description: GitHub Pages and custom domain setup for the Project Alpha docs site.
---

# Docs Site Setup

The Project Alpha docs site publishes from the `main` branch using `/docs` as the GitHub Pages source.

## Repository Settings

In GitHub, use:

| Setting | Value |
|---|---|
| Source | Deploy from a branch |
| Branch | `main` |
| Folder | `/docs` |

## Custom Domain

The custom domain is stored in:

```text
docs/CNAME
```

The file should contain only:

```text
docs.project-alpha.tech
```

## Cloudflare

For `docs.project-alpha.tech`, create:

| Type | Name | Target |
|---|---|---|
| `CNAME` | `docs` | `ledgetoptechnologies.github.io` |

Keep the record as DNS-only while GitHub validates the domain and provisions HTTPS.

## HTTPS

After GitHub validates DNS, enable **Enforce HTTPS** in repository Pages settings.

