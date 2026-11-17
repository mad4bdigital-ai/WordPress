# MAD4B — preserve the existing visitor switcher contract; prevent Wayfatours rollback

## Root cause, 17 Nov 2026

**Not a new switcher or session framework.** The Central `mad4b-platform` already owned `visitor-preferences/v1/state`, `visitor-preferences/v1/apply`, native AJAX saving, and the browser-journey fingerprint. Wayfatours Production lacked the original REST module and its `header-context-controls.js` had reverted to `nativeForm.requestSubmit()` due an older site overlay. This caused full navigation upon selection. An unrelated defect in the source-owned Site Profile origin normalizer also occurred: in an `/es/`-origin `admin-ajax.php` request, WPML translates `home_url('/')` to `/es/` from a same-origin Referer, even though both `wpml_current_language` and `SitePress::get_this_lang()` still report `en`. Site Profile then rejected its canonical origin, which caused server-side signed visitor cookies to be incorrectly treated as invalid across EN→ES.

## Permanent implementation — reuse original engines

1. Keep the original central REST implementation and source-owned header JS, do not create a parallel nationality/market API or duplicate a local data store. Source owner paths are the two existing `mad4b-platform` files in this PR; Wayfatours runtime copies belong to the original MU plugin and JS assets.
2. REST POST verifies exact origin and JSON content type, original WordPress nonces and source-owned country/passport policies; server remains authoritative for cookie signatures, market approvals, and checkout. No frontend market pricing authority is created.
3. Canonical WPML origin handling recognizes *only* an exact `/{active_locale}` suffix on its already-enrolled canonical WP home when running WordPress AJAX or REST, with the canonical `site_url`, exact request host, hidden-language exclusion, environment and deployment binding still enforced. No arbitrary query/referrer path is allowed to mint host authority.
4. Native UI preserves the live Elementor panel ID accessibility fallback. Existing Browser Journey stays fingerprint-only; do **not** store country or passport selection in sessionStorage. Signed HttpOnly provider cookies are read on the next page by the server; no screen refresh occurs during AJAX select changes. WPML pages render the correct state from server.
5. The reconciliation/overlay **manifest** must deploy these exact owner files together for each supported company, never a site-only fallback to `requestSubmit()`. Future CI or host drift checks should fail if either REST contract or JS owner disappears. Company sources remain local to their own host.

## Verified end-to-end

- Before fix: standard signed country AE cookie accepted under `/` Referer, rejected as `DE`/advisory under `/es/` Referer; a real Chrome navigation confirmed the cookie was unchanged and sent.
- After the exact WPML Site Profile normalizer correction, `origin_match=true`, `authority_ready=true`, `deployment_binding_match=true` for `/` and `/es/` AJAX. The strict cross-origin REST request was refused with HTTP 403; audited native state/apply worked with HTTP 200.
- Actual isolated Chromium source canary on **mobile and desktop**: original central JS saved without navigation and preserved AE across EN → ES. No duplicate client-side session state was required.
- Four server-side native switcher operations: Country AE → manual passport GB → Country US (preserving manual GB) → auto passport US; all server contract responses passed without document reload.
- Mobile Critical CSS Async and CSS Async Inline are owned by separate MAD4B engines, untouched. Public HTML HIT and personalized state BYPASS remain separate and expected by privacy policy. Do not make session-marked HTML shared to obtain misleading TTFB parity.

## Repeatable release and rollback

- Inspect SHA256 of the source trio against the exact live target paths; enforce site URL, Site Profile and WPML language admission before any deployment.
- Use the existing MAD4B company reconciliation mechanism, not new site-local duplicate integrations. Deploy source file + JS and restart no business/commerce process.
- After source update, invalidate cached **public HTML representations** that contain old combined JS; never purge CSSJS wholesale or revoke valid critical CSS certificates. Revalidate EN/ES, fresh/incognito and returning-cookie contexts separately.
- On regression, rollback the exact JS/API/Site Profile files from private preimage backups, refresh public HTML representation version, and retain all visitor signed-cookie state and WooCommerce orders unchanged.
