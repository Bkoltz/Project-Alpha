---
title: Tax Rates
description: Manual tax rates and imported state tax data.
---

# Tax Rates

PA supports manual tax percentages, ZIP lookup, county lookup, and official file imports.

## Manual Entry

Use manual tax entry when you already know the rate or need a one-off override.

## ZIP and County Lookup

Document create/edit pages can look up imported rates by ZIP or county. ZIP lookup may show multiple choices when the ZIP crosses jurisdictions or state lines.

If the selected organization is tax exempt, PA shows the tax-exempt banner but does not automatically copy its ZIP into the lookup or apply an imported rate. The tax controls remain available for a manual exception. Selecting a non-exempt client can prefill the service-address ZIP and rank that client's state first when a ZIP crosses boundaries.

## State Imports

The tax import page is state-scoped. Select the state before uploading files. PA keeps separate status for each state and only replaces imported rows for the selected state.

The Tax settings page also shows recent import runs. Refresh the page during a long import to see the current phase, row counts, warnings, and last updated time. Server logs include `tax-import` entries for the same import phases.

## Import Files

PA expects:

- FIPS county file
- Tax rate CSV
- Boundary CSV

If one file changes later, upload only that file for the selected state. PA keeps the other imported tables for that state.

## Large Files

For tax source files over 80 MB, PA uploads the file in smaller chunks before starting the import. This avoids the Cloudflare 100 MB request limit. After the chunks finish, PA starts the normal import using the assembled server-side file.

## Important Tax Note

Imported rates help PA calculate documents, but they do not replace tax advice. Confirm official state and local rules for your business.

