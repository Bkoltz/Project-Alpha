# api — Context

Last updated: 2026-06-20 by Hermes

## What This Is

This folder contains static data files consumed by the Project Alpha UI. There is no executable PHP here; files are read directly by views or dashboard widgets and returned as JSON.

## Files

- `income-data.json` — Array of `{month, income}` records used by the financial dashboard chart (sample/seed data).

## Key Details

- Data shape: `[{"month": "YYYY-MM", "income": 1234.56}, ...]`.
- `month` is an ISO year-month string; `income` is a float representing USD (or the configured currency).
- The file is intentionally small; in production the dashboard is expected to fetch live data from `src/controllers/financial/financial_api.php` and only falls back to this file when live data is unavailable.
- No authentication or API keys are required to read this file because it lives under `public/`-adjacent assets, but the application only exposes it to logged-in users through the dashboard view.

## Dependencies

- Read by views in `src/views/pages/financial/`.
- Format is parsed with `json_decode(..., true)` in PHP or fetched by front-end chart code.
