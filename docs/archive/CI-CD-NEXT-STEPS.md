# CI/CD Historical Notes - June 23, 2026

> **Historical record.** The original file contained point-in-time commit IDs, private infrastructure details, and setup steps that no longer represent the repository. Current workflow files and repository settings are authoritative.

The June 23 work established:

- A Compose smoke-test job for pull requests to `main`, `staging`, and `dev`
- PHP syntax and PHPUnit checks
- Web, database, and cron startup assertions
- GHCR publication from `dev` and `main`
- Separate web and cron image tags
- CodeQL, Gitleaks, and Trivy scanning
- Manual TrueNAS redeployment after image publication

## Current References

- `.github/workflows/ci.yml`
- `.github/workflows/docker-publish.yml`
- `.github/workflows/codeql.yml`
- `.github/workflows/gitleaks.yml`
- [Contributing](https://github.com/ledgetoptechnologies/Project-Alpha/blob/main/CONTRIBUTING.md)
- [TrueNAS Deployment](truenas-scale-deployment.md)

Repository branch protection and auto-merge settings live in GitHub and cannot be inferred from this historical file. Do not store tokens, credentials, private addresses, or production access details in documentation.
