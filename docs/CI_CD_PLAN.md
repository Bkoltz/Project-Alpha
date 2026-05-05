# CI/CD Plan for Project Alpha

## Goal
Set up automated testing, staging deployments, and release management for Project Alpha.

## Current State
- GitHub repo with branches: main, dev, testing
- Branch protection on main (no direct pushes)
- Docker-based application (PHP + MySQL)
- No CI/CD pipeline currently configured

## Proposed Pipeline

### 1. Testing Workflow (on PR to dev/testing)
```
Push to PR branch → Run PHPUnit tests → Run SQL schema tests → Report results
```
- **Tool:** GitHub Actions (free, integrates directly, no extra hosting)
- **Why not Jenkins:** GitHub Actions is simpler for this use case — no server to maintain, works natively with GitHub PRs, free for public repos, 2000 min/month for private repos

### 2. Staging Deployment (on merge to testing branch)
```
Merge to testing → Build Docker image → Deploy to staging server → Notify contributors
```
- **Staging environment:** Could be your existing Docker host or a cheap VPS
- **Access:** Contributors can test without running locally
- **Data:** Use seeded test data, not production data

### 3. Production Deployment (on merge to main)
```
Merge to main → Build release Docker image → Tag release → Deploy to production
```

## GitHub Actions Workflow Structure

### Workflow 1: `test.yml` (Pull Requests)
Triggers: PR opened/synchronized to `dev`, `testing`, `main`

Steps:
1. Checkout code
2. Set up PHP environment
3. Install Composer dependencies
4. Set up test database (SQLite or temporary MySQL container)
5. Run PHPUnit tests
6. Run static analysis (PHPStan/psalm if configured)
7. Report results as PR comment/status check

### Workflow 2: `staging-deploy.yml` (testing branch)
Triggers: Push to `testing`

Steps:
1. Build Docker image
2. Push to container registry (GitHub Container Registry or Docker Hub)
3. SSH to staging server and pull/deploy
4. Run database migrations
5. Post deployment link to Discord/Slack

### Workflow 3: `release.yml` (main branch)
Triggers: Push to `main` OR manual workflow dispatch

Steps:
1. Build production Docker image with version tag
2. Push to registry
3. Create GitHub Release (auto-generated notes or manual)
4. Deploy to production

## GitHub Releases

**Auto vs Manual:**
- GitHub can auto-generate release notes from merged PRs
- You can also create releases manually with custom notes
- For semantic versioning, use tags like `v1.2.3`

**Suggested approach:**
- Auto-generate draft releases on main branch merges
- You review and publish manually with version number
- Include changelog of merged PRs

## Branch Protection Enhancement

Current: No direct push to main

Add:
- Require PR reviews (1-2 approvals)
- Require status checks (tests must pass)
- Require signed commits (optional)

## Security Scanning

For PRs to main:
- Scan for secrets/tokens (use GitHub Secret Scanning or `truffleHog`)
- Check for hardcoded credentials
- Scan dependencies for vulnerabilities (Dependabot already enabled)
- Code quality checks

## Questions to Resolve

1. **Staging server:** Do you have a server available, or should we use something like Railway/Render for temporary staging?
2. **Test database:** Should tests run against SQLite (fast) or MySQL (accurate)?
3. **Notification:** Where should deployment notifications go? Discord channel?
4. **Release cadence:** Deploy on every main merge, or scheduled releases?

## Tools Comparison

| Tool | Pros | Cons |
|------|------|------|
| GitHub Actions | Native integration, free tier, no maintenance | 2000 min/month limit for private repos |
| Jenkins | Full control, unlimited | Requires server, more setup |
| GitLab CI | Good integrated experience | Would need to migrate from GitHub |

**Recommendation:** Start with GitHub Actions. If you hit limits or need more control, migrate to Jenkins later.

## Immediate Next Steps (after finals)

1. Create `.github/workflows/test.yml` for PHPUnit tests
2. Set up GitHub Actions runner (uses GitHub-hosted runners, free)
3. Add basic PHPUnit tests if they don't exist
4. Configure staging deployment to your Docker host
5. Set up branch protection rules for required checks

## Notes

- This is a living document. Update as decisions are made.
- Focus on getting tests running first, then add deployment automation.
- Keep it simple initially — don't over-engineer before you have basic tests.
