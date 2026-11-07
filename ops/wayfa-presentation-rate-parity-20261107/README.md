# 2026-11-07 — Live-code-driven Wayfatours rate filter parity

## Source of truth
The live VPS and running PHP containers, **not the repository branch**, were the starting point. The PR is stacked on `feat/global-age-pricing-policy-jetengine-link-20261102` only to keep its review isolated from unrelated pending changes.

Validated live paths:
- Tours.dev: `/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/mu-plugins`
- Wayfatours: `/opt/wayfa-rebuild-20261102/site-copy/wp-content/mu-plugins`

File contents of the updated shared adapters and the archived company-runtime files were copied directly from **Wayfatours live**, then compared byte-for-byte against the committed branch versions. Do not back-port the older Git versions over live files.

## Scope of this review
- Presentation-only runtime loads the live Rate eligibility/facet adapters, reusing the original pricing `pricing_audience_quote` and `pricing_audience_display` display logic. It does not enable checkout, payment, supplier actions, or Booking v2 writes.
- Only `cruise_tour` can offer a PROGRAM choice, and only with supported reusable programs and Rate eligibility. Single Tour itineraries must not be turned into duration-based programs.
- Rate facets preserve the initial localized JetSmartFilters placeholder, including Spanish `Seleccionar paquete`.
- Company-level Program visibility adapter and Presentation provider loader are archived separately from shared core.
- Source's scoped anonymous Visitor State Lean Bootstrap is archived in this PR; personalized/authenticated requests retain the full WordPress bootstrap.

## Live data reconciled independently of Git
- On Wayfatours: 32 missing `related_package_term_id` records were restored to their verified existing base term Gold / `2354`: 24 records from public Tours and 8 from Draft parents. No post statuses or Rate amounts were changed.
- WPML Rate `61052` rejoined its source EN/ES Rate group `1218516` via the WPML hook. Previous `trid` was `1218567`; source EN Rate is `61050`.
- Receipts and rollback metadata exist **on VPS only** at `/opt/mad4b-company-provisioning/reports/rate-program-perf-deep-review-20261107/`. Do not copy DB, credentials, or user state into Git.

## Verified
- Mobile Spanish Tour `60655`: package options `Seleccionar paquete`, `Oro` (2354); selecting Oro changes the native JetSmartFilters URL and receives HTTP 200 AJAX. PROGRAM hidden.
- Native Cairo archive deep link: HTTP 200; `Cairo` H1, Cairo checkbox selected, 15 Tour results, DFSB endpoint HTTP 200, no page JS errors.
- Single-itinerary Cruise: PROGRAM hidden, no invented selectable term.
- Tours.dev anonymous Visitor State AJAX after lean bootstrap: 135–176 ms across three samples vs 756–824 ms before, same response contract/body, POST and login-cookie scenarios preserve full bootstrap.
- Read-only PHP-phase profiling on both sites completed and the temporary MU profiler removed.

## Performance follow-up (not a pass)
On cold uncached requests, Elementor nested Tour content and JetEngine Listing `56197` dominate, not the Query Builder argument filter. On Wayfatours the slowest Tour template widgets were `f150fa2` / `1985c71`; page samples exceeded 5 seconds and executed hundreds of SQL queries. Profiling numbers for nested widgets overlap. Audit and improve this rendering chain separately; never disable required booking/price logic to save time.

## Outstanding
- There are no verified multi-program Cruise records with eligible linked rates. Live multi-PROGRAM selection cannot be certified from a single-itinerary Tour.
- Reconcile any remaining WPML translation metadata cautiously; do not assume identical local term translation IDs.
- Preserve the 30-minute HTML cache policy and per-visitor privacy conditions.

## Deployment precautions
This PR contains live snapshot code. Do **not** merge or deploy over a newer environment until fresh per-file hashes and a three-way merge confirm no later site changes. Production remains controlled separately from source Staging.
