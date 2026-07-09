# GitHub Container Registry release guide

Project Alpha publishes container images to GitHub Container Registry, not Docker Hub:

- Web image: `ghcr.io/ledgetoptechnologies/project-alpha:<tag>`
- Cron image: `ghcr.io/ledgetoptechnologies/project-alpha:cron-<tag>`

For release candidates, use Git tags. Do not make a long-lived branch named `0.5.0-rc1`; the branch is only a workspace for release preparation. The tag is the frozen release marker that triggers image publishing.

## First-time GitHub setup

1. Push the repository to GitHub.
2. Open the repository on GitHub.
3. Go to **Settings > Actions > General**.
4. Under **Workflow permissions**, choose **Read and write permissions**.
5. Save.

The Docker workflow uses the built-in `GITHUB_TOKEN`; no Docker Hub account, Docker Hub organization, or registry password is required.

After the first image publish, verify package visibility:

1. Open the repository on GitHub.
2. Find **Packages** in the repository sidebar, or open the organization/user **Packages** page.
3. Open the `project-alpha` container package.
4. If the package is private and you want public pulls, change package visibility to public.

Public visibility matters for TrueNAS/community installs because users must be able to pull the image without logging in.

## Release candidate flow

Use this flow for `0.5.0-rc1`.

```bash
git checkout -b release/0.5.0
git add .
git commit -m "Prepare 0.5.0 release candidate"
git push -u origin release/0.5.0
```

Open a pull request from `release/0.5.0` into the branch you use for releases, usually `main`.

After CI is green and the PR is merged, tag the exact release candidate commit:

```bash
git checkout main
git pull
git tag v0.5.0-rc1
git push origin v0.5.0-rc1
```

That tag push publishes:

- `ghcr.io/ledgetoptechnologies/project-alpha:0.5.0-rc1`
- `ghcr.io/ledgetoptechnologies/project-alpha:cron-0.5.0-rc1`
- `ghcr.io/ledgetoptechnologies/project-alpha:sha-<commit-sha>`
- `ghcr.io/ledgetoptechnologies/project-alpha:cron-sha-<commit-sha>`

Use the `0.5.0-rc1` tags for staging and TrueNAS testing. Avoid using `latest` for release validation.

## Verify the images after publishing

On a machine with Docker:

```bash
docker pull ghcr.io/ledgetoptechnologies/project-alpha:0.5.0-rc1
docker pull ghcr.io/ledgetoptechnologies/project-alpha:cron-0.5.0-rc1
```

Then update a test Compose file to use those two tags and perform a fresh install test.

## If the image pull fails

Check these in order:

1. The GitHub Actions run for the tag completed successfully.
2. The package exists under GitHub **Packages**.
3. The package visibility is public, or the target machine has authenticated to GHCR.
4. The tag name matches exactly, for example `0.5.0-rc1`, not `v0.5.0-rc1`.

Git tags include the `v` prefix. Container image tags do not.
