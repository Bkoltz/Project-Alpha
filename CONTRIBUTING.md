# Contributing to Project Alpha

Project Alpha welcomes bug reports, documentation improvements, and focused code contributions.

## Report a Bug

Open a [GitHub Issue](https://github.com/ledgetoptechnologies/Project-Alpha/issues) and include:

- A short, specific title
- Project Alpha version or image tag
- Document family and status involved
- Steps to reproduce
- Expected and actual behavior
- Sanitized logs or screenshots when useful

Never post passwords, API keys, payment data, private URLs, customer names, customer documents, or other sensitive information.

## Branches and Pull Requests

- `main` is protected and receives production releases through pull requests.
- `dev` publishes staging images and is the normal integration branch.
- Use a focused branch for changes that will enter protected `main`.
- Small, low-risk changes may be committed directly to a non-protected integration branch when appropriate.
- Keep unrelated refactors out of bug-fix pull requests.

Reference the issue in the pull request. Use `Fixes #123` when merging the pull request should close the issue.

## Verification

Testing should match the risk of the change:

- Documentation: Markdown, link, and command review
- PHP logic: syntax check and relevant PHPUnit tests
- Database changes: migration dry run, fresh-install test, and upgrade test
- User interface: browser verification at desktop and narrow widths
- Payments or public links: Stripe test mode and webhook verification
- Scheduled tasks: run the affected script manually and inspect its log/state record

## Development Commands

```bash
docker compose -f docker-compose.yml -f docker-compose.override.yml up -d --build
composer install
composer test
```

Read [docs/AGENTS.md](docs/AGENTS.md) before making structural changes and [docs/DOCUMENT_WORKFLOW.md](docs/DOCUMENT_WORKFLOW.md) before changing document behavior.

## Database Changes

Every schema change must include:

1. An idempotent forward migration in `database/migrations/`
2. The same final schema in `database/init.sql`
3. Safe defaults or nullable columns for existing installations
4. Verification against staging before production deployment

Do not edit a live production database manually.
