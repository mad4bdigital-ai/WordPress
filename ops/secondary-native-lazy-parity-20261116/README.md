# MAD4B native JetEngine conditional listing AJAX parity gate

## Diagnosed on Tours.dev on 2026-11-16

Native signed JetEngine AJAX is supported, but conditional public terms listings
can lose their original queried-object context. Browser A/B canaries of two
homepage grids at 390px gave the following results.

- Attraction terms listing: SSR had 45 nested JetEngine listing items, AJAX
  returned 2, with different first links. The click/scroll QA failed.
- Featured tour posts listing: 20 nested items and first links matched, but the
  SSR block was about 7338px tall and its native lazy placeholder only 36px.
  CLS after scroll was about 0.455, above the 0.10 budget.
- A combined canary yielded CLS about 0.792.

These diagnostics MUST NOT be treated as a certified performance release.

## Persistent rule

The existing mad4b-secondary-listing-lazy.php was newer on the live source
(1.0.10) than in the selected Git branch (1.0.3). Preserve/forward-port the
LIVE implementation before changing its admission rules. Release 1.0.11 adds
mad4b_secondary_grid_lazy_ajax_parity_proven, a read-only failure-closed
certificate gate used by automatic conditional term lazy rendering.
It reuses the existing company-local layout proof registry:
mad4b_secondary_grid_lazy_layout_proofs_v1.

To be admitted, each of the site's mobile and wide viewport bands must carry
ajax_parity_by_market[WCML country-currency] evidence, with:
- Current site host, locale, CSS epoch, Elementor document SHA and query data
  generation.
- Current company WCML country and currency; no caller-supplied market header.
- Native signed AJAX verification, exact item counts, ordered link/content
  SHA-256 equality between SSR and Ajax, measured final CLS <= 0.10.
- Browser coverage through current Elementor mobile breakpoint and 1920px.
- A local proof timestamp no older than seven days.

Missing, stale or conflicting evidence preserves complete native SSR, never
a placeholder. It does not affect explicitly authored JetEngine lazy loading,
private sessions, transactions, rates, payments or other pages.

Existing old layout-only proof entries are intentionally NOT upgraded or
reused as AJAX parity proof; all four legacy EN/ES entries remain uncertified.

## Evidence and rollback

Source: tours.dev.mad4b.com staging.
Live preimage 1.0.10 SHA-256:
ed5905b2489de38839a51a181d288c9a6cb01cf602b5d52d93a2d14ffb329671.
Live patched 1.0.11 SHA-256:
f7a974ef75f61be6e4c861e7cabd8ddacbdb250b6a96decd1b1a8a0014eddb76.

Regression test:
php tests/test-native-lazy-ajax-parity.php <candidate-plugin.php> (16 assertions).

After patch: EN public mobile had same 1,467,938-byte server-rendered HTML,
with 20 featured-tour nested item tags and 43 attraction-term item tags in a
direct HTML parse both before and after. No new lazy wrappers, and Critical CSS
remained ready. Browser DOM can add items dynamically, so compare equivalent
server-rendered snapshots.

Re-certifying certificates can only occur after a separate offline browser
worker proves SSR/AJAX item parity, CLS and accepted current WCML market in
each locale and band. No certificate was faked or installed.

Production (Wayfatours) was not modified; its runtime/source ownership differs.

## Follow-up strengthening: version 1.0.12

In addition to the v1.0.11 country/currency, query generation, route and
viewport assertions, the browser proof must bind the actual current Elementor
listing _elementor_data SHA-256 and its generated post-N.css SHA-256. On both
mobile and desktop, the existing proof entry must match both sources.
The active Elementor kit viewport_mobile must also match the proof's enrolled
breakpoint. Changes or missing files fall back to original server rendering.
This closes a stale-proof gap that previously existed on desktop where the
mobile height validator was not run.

Version 1.0.12 patch SHA-256:
6eb39cfb618a4583eb3ab5796a714de1f54a055d9b4b4b226c51cdf4c37bb3a4.
Additional negative tests prove changed listing data, altered generated CSS
and changed Elementor kit breakpoint cannot reuse old evidence.
Regression suite now has 20/20 successful assertions.

No conditional grid was enrolled or forced into lazy rendering during
production publication. Source Tours.dev remains at CSS epoch 123; older layout
proofs remain at epoch 122. Never advance their epoch without browser recertification.

## Native scoped Query Builder generation, 2026-11-16

Auto-lazy v1.0.13 now gets the current true source-scoped generation from
mad4b_render_query_generation_for_id(). An unrelated legacy fragment global
epoch does not invalidate a valid component certificate, while actual CPT or
term-dependency edits do. The updated regression has 21/21 assertions.
Do not replace the newer 0.3.27 Critical CSS orchestrator from this branch.

## Superseded by 2026-11-16 safe-default v1.0.16 release

The earlier statement that no layout proof was enrolled is historical.
The later release in ops/native-lazy-default-v1016-20261116 supersedes
it for certified Tours.dev DE-USD cohorts. The root cause was the native
Inner Progressive first-batch truncation and desktop missing CSS height.
Only independently audited source- and market-matching cohorts auto-load.
