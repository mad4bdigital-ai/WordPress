# MAD4B Frontend Impact Priority — rollout and acceptance

## Sources

- Control Plane read-only scanner: `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-frontend-impact-priority.php`
- Optional MU bootstrap: `wp-content/mu-plugins/mad4b-frontend-impact-loader.php`
- Shared Product / Presentation source-of-truth adapter: `wp-content/mu-plugins/mad4b-frontend-impact-shared-presentation-bridge.php`
- Source-backed rate, booking form and catalog issue adapter: `wp-content/mu-plugins/mad4b-frontend-impact-commerce-evidence.php`
- Acceptance checks: `wp-content/plugins/mad4b-site-control-plane/tests/frontend-impact-component-evidence-test.php`

## Existing Control Plane comparator

The full, source-verified Staging `class-mad4b-scp-content-readiness-alerts.php` has now been reconciled directly to its canonical GitHub path, including the in-tier `mad4b/content_readiness/impact_score` comparator. Its native dependencies `class-mad4b-scp-readiness-workflow-options.php` and `class-mad4b-scp-content-completeness.php` were reconciled as well. The two newly tracked admin submenu modules now use `MAD4B_SCP_Admin_Route_Registry` to honor the existing parent-order contract. `mad4b-site-control-plane.php` explicitly loads and boots Readiness and Impact, and classifies `/mad4b-frontend-impact/v1/` as an owned REST namespace. The separate `readiness-comparator.patch` is a historical reconciliation aid, not an unapplied blocker.

Priority overlay is read-only and affects **sorting within existing severity**; it never replaces the provider score in the issue ledger or changes readiness/ContentJob states.

## Live Staging acceptance (2026-11-02)

- Initial six-language home scan: 6 pages, 105 WPML destination alerts, 28 parent groups.
- Extended scan from live WP-Cron readback: **18 HTML pages**, **121 scored existing alerts**, 39 groups, no HTTP failures.
- Scoring categories: 105 destination navigation; 10 verified expired Native Rate-to-tour relations with published listing visibility; 1 form follow-up; 1 price-locale parity; 1 catalog layout; 1 rate CSS; 1 site performance; 1 stale probe.
- Cross-language archive filters: EN/FR/DE/ES/IT/AR present. No ungrounded JSF parity alerts.
- Real Spanish tour static DOM: Native Shared Product Form 6 present via the MU booking adapter, price surfaces present. This does **not** verify a transaction.
- 11/11 standalone contract assertions PASS, including fail-closed for unobserved rate and form issues and no booking/publication authorization.
- `GET /`, `/fr/`, `/es/`: HTTP 200, TLS valid.
- New manual full-scan retries sometimes return `STAGING_WPCLI_BUSY` due to concurrent WordPress CLI sessions. Do not bypass the lock. The last successful persisted readback remains authoritative; do not claim a subsequent scan succeeded without checking its timestamp.

## Browser-driven acceptance (2026-11-02)

- Host-side Chromium/Playwright performed **14/14 PASS** interactions against real Staging public pages. Repeatable script: `wp-content/plugins/mad4b-site-control-plane/tests/frontend-impact-live-browser-acceptance.py`; structured evidence: `ops/staging/tours-dev/frontend-impact/evidence/browser-acceptance-20261102.json`.
- English **Packages**: opened `Select Tour Type` Accordion, clicked the genuine JetSmartFilters label; 78 rendered listing items before, 58 after, native JSF URL updated, two AJAX 200s.
- Spanish **Paquetes**: clicked genuine visible label, 78 items before, 57 after, matching locale JSF URL, two AJAX 200s.
- English and Spanish real published tour pages: scrolled to Form 6 as needed, then clicked native `+` (adults 2→3) and `−` (3→2). No Submit, Checkout, Woo Order, or supplier POST; no page JavaScript errors observed in these tested pages.
- The English filter Accordion is intentionally closed initially; the Spanish filter panel was initially visible. This difference in default presentation is not the same as failure to filter, and may be considered separately for design parity.
- Explicit `GET /wp-json/mad4b-frontend-impact/v1/summary` unauthenticated returns HTTP **401**, while a verified WordPress administrator REST dispatch returns **200**, with 18 sampled pages and 121 ranked issues. This is a summary/read-only route.
- Read-only comparator test PASS using actual persisted issues with overlay 91 vs 25: higher impact wins within severity; Critical still supersedes High; resolved issues remain last and source issue ledger is unchanged.
- Scoped PHP lint PASS; public home and WPML pages return HTTP 200. Production not modified.

## Release gate

The PR remains Draft until GitHub Actions conclude successfully, all required reviews/authority are satisfied, and a release deployment is approved. This PR may introduce canonical readiness source that was previously absent from GitHub but active on Staging; CI admin route checks apply. Never treat an unfinished GitHub workflow as PASS.

## Exposure / safety limits

- Live evidence is server-rendered HTML, not computed browser visibility. A DOM element may still be hidden by CSS or disabled by JS. Candidate parity differences are **not** automatically published as issues.
- Native booking Form 6 identification comes from availability of the Shared Product form6 quote adapter, not from generic text guessing. An HTML presence check is **not** a successful booking or payment proof.
- Only eligible and exactly mapped pre-existing alerts are scored. Other alerts keep their normal severity/priority; source and issue IDs are never fabricated.
- No Production deployment, supplier transactions, price changes, or job mutations.
- The latest snapshot is isolated in `mad4b_frontend_impact_priority_v1`; failed coverage does not erase a last good snapshot. The REST summary is authenticated.
