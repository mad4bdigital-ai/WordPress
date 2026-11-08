# MAD4B fleet browser HTML policy — public vs safe-cookie private (2026-11-08)

## Live outcome

Tours.dev and Wayfatours now enforce the same request-context decision at their Nginx edges:

| Request context | Required HTTP Cache-Control | Origin cache |
|---|---|---|
| Cookie-free, approved public page | `public, max-age=1800, must-revalidate` | FastCGI HIT/MISS/STALE |
| Allowlisted analytics or matching WPML locale cookie, approved public page | `private, max-age=1800, must-revalidate` | FastCGI remains eligible |
| Auth, unknown cookies, mismatched locale, market, currency, geo, query, cart, checkout or private WordPress context | `private, no-store` (additional directives permitted) | BYPASS or private handler |

Important: `private` is a browser/shared-proxy **response directive**; it does not alone determine Nginx FastCGI HIT/MISS. Origin cache gate remains responsible for request classification. No full purge or Redis flush is necessary for this change.

## Source Tours.dev

The former `mad4b_browser_html_coherence` map permitted a public response for allowlisted tracking/WPML cookies. The new map incorporates `$mad4b_browser_cookie_free`. An empty Cookie header returns 1, all nonempty headers return 0. Only public WordPress opt-in, non-private upstream response, non-sensitive request, and no upstream Set-Cookie can emit `public`; otherwise allowlisted nonempty Cookie → `private, max-age=1800`, and every rejected context → `private, no-store`.

The Nginx configuration was found already identical to the prepared patch during implementation (a concurrent update). No overwrite was performed. Live readback and Nginx syntax confirmed the new policy, followed by direct smoke tests in all six source languages, safe marketing cookies (FastCGI HIT), matching and mismatching WPML language cookies, and auth/checkout.

## Wayfatours

Wayfatours already had the same three-way browser response model: Nginx-approved cookie-free HTML is public; allowlisted nonempty Cookie requests remain browser-private with FastCGI eligibility; sensitive requests bypass. This rollout did not alter Wayfatours config. Its previously repaired FastCGI root ownership remains guarded.

## Dynamic fleet drift reconciliation

`company-browser-cache-context-reconcile-v1.py` reads the existing `company-cache-coherence-v1.json` desired-state policy: **no company-specific URLs, languages or ID lists are embedded in the fleet observer**. It discovers each `company_sites` origin and the `cookie_decision_model.sites` languages; unknown or unenrolled companies produce `UNENROLLED_BROWSER_POLICY` rather than silently entering a shared cache.

The observer performs bounded HTTP GET probes for every language homepage, safe analytics and matching-language cookie variants, deliberate locale mismatch, fake auth cookie, custom currency header, query string and checkout. It asserts one Cache-Control header and the appropriate FastCGI cache status when present. The canonical report is `/opt/mad4b-company-provisioning/reports/company-browser-cache-context-current.json`, containing per-company pass/fail evidence, bounded HTTP response metadata, durations, run ID and entry point. It never collects cookie values, HTML bodies, private identities, or Redis credentials.

A root-owned, resource-limited systemd timer runs every 30 minutes. It **detects and reports** drift; it never changes Nginx configs, flushes Redis, clears HTML or invalidates price data. Failure is surfaced in the JSON verdict and systemd service exit. Runbook: inspect failed probe class and relevant Nginx/WPML cache eligibility maps; validate PHP private context; fix configuration first in staging and rerun the observer; do not broadly purge.

## Acceptance/rollback

Manual read-only fleet audit: **28/28 PASS** (Tours.dev 18/18; Wayfatours 10/10). Separate source live probes confirmed `private + FastCGI HIT` for analytics, language and tracking cookies. Prior and candidate exact source Nginx configs and a unified patch are saved under `/opt/mad4b-company-provisioning/reports/cache-browser-policy-unification-20261108/`.

To reverse only the source browser header change, restore its old Nginx config in place preserving the Docker bind mount inode; validate `nginx -t` and reload. For the monitoring service, disable `mad4b-company-browser-cache-context.timer`. Do not clear Redis, FastCGI or WPML state. PR #781 remains Draft pending independent GitHub CI.

