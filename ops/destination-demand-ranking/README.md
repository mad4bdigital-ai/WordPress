# Destination Demand Order — presentation-only source contract

Read-only company-local ranking shared by Wayfatours and Tours.dev, with no hard-coded country, environment, WPML language, tour IDs, or terms. The native JetEngine query controls **membership**; a request-local, bounded WP_Query visibility index ranks the returned entries by the number of **distinct public tour posts the visitor can browse** in the active WPML language. It reuses the canonical WordPress/WPML query filtering of JetEngine Query `273`, including allowed English fallback for missing translations, both the translated destination term and its source term, the `extra_trip` exclusion, and company-local publication rules. Ties retain source order.

## Surfaces

* `jet-engine/query-builder/query/items`, after the existing destination relationship/eligibility provider, for Terms Queries whose taxonomy is exactly `destinations` and whose `destination_type` meta query is `city` or `state_governorate`. Query IDs are discovered by **data contract**, not by fixed Elementor template IDs. Explicitly custom/editorial order (`term_order`, `include`, custom meta ordering) is left intact.
* Native Elementor menu Jet Listing `67125` (`navigation-editor-grid`): sort the *existing* `include` IDs **before** pagination via WordPress `orderby=include`. Every prior company/service activation and sale eligibility guard remains authoritative.
* Native nested Elementor tabs: one read-only filter at the end of `native_destinations_eligible_children` sorts only its already-approved localized IDs. Source module and older Wayfatours MU compatibility overlay both expose that filter.
* The old Query `288` homepage-only plugin continues as a fallback when the shared module is not installed.
* One request-scoped WordPress ID-only query (not one per city), followed by one indexed taxonomy relationship lookup; no new visitor-side assets or preloading. The ranking is capped at 3000 tour posts: if the catalog exceeds that bound the existing editorial order is retained rather than using an incomplete count.
* Use the **visible** tour count, not the raw `WP_Term.count`, nor the localized-only SQL count. WPML fallback can make the frontend count higher than the number of directly tagged translated posts, notably for FR/DE/AR on Tours.dev.
* The shared filter remains read-only. Translation state, display text, term memberships, tour publication and Elementor widget settings are not modified.

## Non-goals

Do not reorder attractions, geographical hierarchy, countries, zones, user-authored editorial placements, search/relevance queries, supplier listings, Rates or commerce. Do not infer sale eligibility from tour counts. Do not mix WPML translations or companies. The `12`-item maximum in the existing `Shared_Product_Destination_Term_Presentation_v1` relation engine remains in place; this rank service never broadens that set.

## Runtime acceptance

Run `wp eval-file tests/destination-demand-ranking/live-contract.php` on each active company and use the Elementor menu browser smoke. For future companies, distribute the shared MU plugin and the native menu owner bridge according to that company's active source map; do not install the Wayfatours overlay on unrelated companies.

## Header on-intent lazy delivery

The shared presentation policy sets the same server-side order for every
company-local menu. The site's existing native Elementor/JetEngine header may
render the root country listing as an authenticated, signed Lazy Listing; the
`mad4b-navigation-lazy-listings.js` owner now starts the native JetEngine
widget handler on **user opening** the menu, after its native panel settles.
No menu images or listings are requested by that correction at first page load,
no hand-built Ajax parameters are submitted, and previously immediate menus
(e.g. Wayfatours) remain immediate. Deep tabs continue their separately bounded
signed Lazy Listing service; the root handler explicitly ignores deep branches.
The browser acceptance script checks representative company and WPML variants
and waits for actual JetEngine completion rather than treating a slow backend
response as an ordering mismatch.

## Final integration / durable company release

The single source of executable ranking logic is the portable root MU file
`wp-content/mu-plugins/mad4b-destination-demand-rank-v1.php`. Tours.dev
registers the **thin** `mad4b-platform/modules/presentation/mad4b-destination-demand-rank-v1.php`
bridge through the canonical 379-entry MU map and matching signed integrity data.
Wayfatours operates without that platform directory and remains the same
root MU owner (no platform-plugin dependency or duplicate logic).

Future independently enrolled companies may request the read-only reviewed
`mad4b-destination-demand-order` component from the exact Git release catalog,
with their own runtime binding, per-company source/file CAS and original
signed release admission. Catalog registration never implies deployment authority.
Existing `service-activation`, `destination-activation`, sale eligibility,
WPML language selection, and native Elementor projection retain all membership
and permission decisions; the rank engine only orders already eligible results.
The new `transition_post_status` hook invalidates public HTML through the
existing safe broker only when a tour *enters or leaves* publication, closing
the gap where no taxonomy relationship mutation occurs. Other term/option
changes already have authoritative invalidation hooks and must not be doubled.

The obsolete optimized navigation script and its stale `mad4b-static-js`
manifest entry were removed from the *current* source tree after verifying
that neither runtime references them. Their former hashes remain in Git history
and in the historical `runtime-owner-closure-v1` evidence snapshot, which is
not rewritten. The existing two other optimized assets are untouched.

The active native header now loads a **content-addressed physical JS path**:
`/wp-content/mu-plugins/mad4b-navigation-lazy-listings/navigation.<sha12>.js`.
The PHP loader checks the immutable file against the SHA-256 pinned at
build time in the loader, so the new company release is self-contained (PHP
loader plus its immutable JS) and does not depend on the old mutable asset. This avoids stale browser/CDN copies even though
MAD4B strips query string versions and the old mutable JS URL can be cached for
30 days. If a future release forgets the corresponding immutable artifact,
the functional fallback remains available but acceptance must **fail closed**
for this deployment until the matching file is included. No CSS/image prefetch,
extra initial network request, or modification of signed JetEngine lazy Ajax.

`python3 ops/destination-demand-ranking/build-navigation-asset.py --check`
verifies the canonical JS, immutable file and release catalog. Run the same
script with `--write` only when the JS source is changed in the release: this
updates its immutable file and pinned component paths and removes superseded
immutable files in the dedicated source-owned asset directory. This is a
build-time action, never a visitor-side filesystem write. The test protects
new company provisions, source-to-tenant sync and future live deploys. The
historical static minifier is disabled for this JS until a properly rebuilt
artifact and browser regression can be independently certified.
