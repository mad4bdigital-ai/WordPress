# Wayfatours anonymous public HTML header correction — 8 November 2026

## Live assessment across two companies

**Tours.dev (source)** already returns `Cache-Control: public, max-age=1800, must-revalidate` on English and Arabic public homepages (200, FastCGI HIT/MISS), and `private, no-store` on commerce pages. Its Nginx cache root and descendants belong to the running worker UID 33. **Source was reviewed and deliberately left unchanged.**

**Wayfatours (Production)** returned FastCGI `HIT` but sent `Cache-Control: private, max-age=1800` even for cookie-free, WordPress-approved public pages. The Nginx `$wayfa_browser_html_policy` map caused that mismatch, not WordPress or Redis.

A separate issue was discovered during the first reload: the Wayfatours FastCGI cache root had legacy UID **101:33** with mode **0700**, while its Nginx master/workers run as **33:33**. This caused observed `open() ... Permission denied` and HTTP **500/502** on cache reads/writes. The configuration was immediately rolled back, and only that cache root's owner was changed to 33:33, preserving all cache files. Homepage and Spanish homepage returned 200/HIT again.

## Deployed header behavior

The Nginx change is **Wayfatours-only**, retains existing FastCGI cache keys and 1800-second lifetime, and treats exactly cookie-free WordPress-opted-in GET pages as browser-public:

| Request | Browser Cache-Control | FastCGI |
|---|---|---|
| Cookie-free EN/ES homepage and published public tour | `public, max-age=1800, must-revalidate` | HIT or STALE as appropriate |
| Allowed analytics/WPML cookie | `private, max-age=1800, must-revalidate` | Per-cookie-class existing key |
| Auth, commerce, cart, checkout, query params | `private, no-store, max-age=0` | BYPASS |

Host policy and WordPress eligibility remain the security gates; no blanket `Cache-Control: public` override is used. No Redis flush, HTML-cache purge, or global epoch rotation was executed.

**Live acceptance:** after waiting for the asynchronous Nginx worker handoff on reload, **11/11 HTTP checks passed** on Wayfatours including EN/ES desktop, mobile, safe cookies, auth cookie, cart, checkout and query bypass. Source public/private probes also passed. Pricing Cache and Cache Control Readiness both PASS 2/2, DRIFT 0. Nginx syntax and reload PASS.

## Ongoing root-ownership prevention

`mad4b-nginx-fastcgi-cache-owner-guard-v1.py` plus a systemd timer every **3 minutes** checks the fixed enrolled source and Wayfatours container/cache-root pairs. If a cache root is precisely **101:33:700** and the running container user is **33:33**, it may correct the *root directory only* to 33:33. Any other UID/GID/mode, symlink, or nested file/dir ownership drift is reported, never changed recursively. There are no Nginx reloads or cache/Redis mutations from the guard. Current guard **PASS 2/2**, no changes required.

The guard uses concrete registered container names: a container-renaming/reprovisioning operation requires updating enrollment. It is not a generic shell executor.

## Evidence and rollback

VPS reports are in:
`/opt/mad4b-company-provisioning/reports/wayfa-anonymous-public-cache-control-20261108/`

Files include `FINAL-ACCEPTANCE.json`, `FINAL-CHECKPOINT.json`, `nginx-before.conf`, `nginx-candidate.conf`, and cache ownership evidence.

Rollback: restore the prior Wayfatours Nginx config **in-place** (preserve Docker bind-mount inode), validate `nginx -t` and reload. To disable automatic permission checks, disable `mad4b-nginx-fastcgi-cache-owner-guard.timer`; do not clear Redis or remove cache files. Keep source unaffected.
