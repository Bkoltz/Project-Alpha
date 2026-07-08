# GitHub Pages Setup

Project Alpha publishes documentation from the `main` branch using the `/docs`
folder as the GitHub Pages source.

## Repository Settings

In the GitHub repository, open **Settings > Pages** and use:

| Setting | Value |
|---|---|
| Source | Deploy from a branch |
| Branch | `main` |
| Folder | `/docs` |

The default Pages URL for this repository is:

```text
https://ledgetoptechnologies.github.io/Project-Alpha/
```

GitHub Pages can take several minutes to publish after changes are pushed.

## Custom Domain

Before adding DNS records for Pages, verify the domain in GitHub to reduce the
risk of someone else claiming it for a Pages site:

1. Open the `ledgetoptechnologies` organization settings on GitHub.
2. Go to **Pages > Verified domains**.
3. Add the domain and create the TXT record GitHub gives you in Cloudflare.
4. Keep the TXT record after verification succeeds.

In **Settings > Pages > Custom domain**, enter the exact hostname you want
GitHub Pages to serve, then save it. When this site is deployed from a branch,
GitHub creates or expects a `CNAME` file in the publishing source. For this
repository that file belongs at:

```text
docs/CNAME
```

The file must contain only the hostname, for example:

```text
docs.project-alpha.tech
```

Do not include `https://`, a path, comments, or extra lines.

## Cloudflare DNS

Use one of these DNS setups, depending on the hostname you choose.

Avoid wildcard DNS records such as `*.project-alpha.tech` for GitHub Pages.
In Cloudflare, leave the Pages DNS record as **DNS only** while GitHub validates
the custom domain and provisions HTTPS.

### Subdomain

For a subdomain such as `docs.project-alpha.tech` or `www.project-alpha.tech`,
create a Cloudflare DNS record:

| Type | Name | Target |
|---|---|---|
| `CNAME` | `docs` or `www` | `ledgetoptechnologies.github.io` |

The CNAME target should be the organization Pages host only. Do not include
`/Project-Alpha`.

### Apex Domain

For an apex domain such as `project-alpha.tech`, create Cloudflare DNS records:

| Type | Name | Value |
|---|---|---|
| `A` | `@` | `185.199.108.153` |
| `A` | `@` | `185.199.109.153` |
| `A` | `@` | `185.199.110.153` |
| `A` | `@` | `185.199.111.153` |

Optional IPv6 records:

| Type | Name | Value |
|---|---|---|
| `AAAA` | `@` | `2606:50c0:8000::153` |
| `AAAA` | `@` | `2606:50c0:8001::153` |
| `AAAA` | `@` | `2606:50c0:8002::153` |
| `AAAA` | `@` | `2606:50c0:8003::153` |

If both apex and `www` should work, configure the apex records above and add a
`CNAME` for `www` pointing to `ledgetoptechnologies.github.io`.

## HTTPS

After DNS resolves and GitHub finishes checking the domain, enable
**Enforce HTTPS** in **Settings > Pages**. GitHub may need up to 24 hours before
the option becomes available.

## Verification

On Windows, DNS can be checked with PowerShell:

```powershell
Resolve-DnsName docs.project-alpha.tech -Type CNAME
Resolve-DnsName project-alpha.tech -Type A
```

Replace the hostnames with the custom domain you actually configured.
