# MAD4B Taxonomy Evolution — governed reusable rollout

This is **a source-only draft of a reusable operation**. It does not alter live taxonomies or grant executor authority. The actual permanent migration must use the original MAD4B dispatcher, site-bound grant, certified provider adapters, and independently verified rollback.

## Four-site lifecycle
For each enrolled company site, inspect the native JetEngine/WPML/WordPress state and classify into exactly one branch: `MIGRATE_LEGACY`, `ADOPT_CANONICAL_NOOP`, `REVIEW_DUAL_IDENTITY`, or `PROVISION_SCHEMA`. Produce a separate exact inventory receipt, dependency graph, localization visibility result, plan hash, native execution grant, restore receipt, readback and audit event per site. Never copy one site's local term IDs, WPML `trid`, or authority into another site.

**Mandatory shared definitions:** post types, taxonomy definitions, queries, glossaries, CCT/meta schema. **Selected assets:** filters, terms, content, listings, Elementor templates and pages are derived from Product Activation. Hidden/active WPML languages do not imply publish eligibility.

## Observed real-data canaries (2026-11-01)
* **Egypt Tour Gates Staging**: `location_jet` legacy, target `destinations` absent. Real database rows in isolated temporary MySQL copies: 1 JetEngine definition, 438 taxonomy rows, 434 WPML translation records, all round-tripped with matching rollback SHA256. A separate test preserved 2,568 term relationships and round-tripped 12 direct JetSmartFilters bindings plus 46 menu bindings.
* **All Royal Egypt Staging**: same legacy-only state; separate temporary-table test passed 1 definition, 316 taxonomy rows and 316 WPML translation rows, with exact rollback hashes. Original WordPress tables were not updated. The published GitHub script was directly executed on ETG; its cross-site replay to ARE was blocked by transport safety, although a separate in-line native test had passed there.
* Third and fourth sites are **not independently identified or tested**. Four-site classification was separately exercised with local synthetic fixtures (48 assertions), not claimed as four live sites.

## Reproducible staging-only native shadow
The adjacent `wpcli-native-shadow.php` uses only connection-local MySQL TEMPORARY TABLES, and validates exact home/environment and source/target slugs through `MAD4B_EVOLVE_EXACT_HOME`, `MAD4B_EVOLVE_OLD`, and `MAD4B_EVOLVE_NEW`. It deliberately will not run on Production and never authorizes a persistent apply.

## Blockers to permanent apply
1. Canonical versioned `destinations` source schema and exact provider certifications.
2. Original site-bound authority plus an independently tested restore point.
3. Migrate/repair complex serialized and JSON config via certified Elementor, JetEngine, WPML, Rank Math and JetSmartFilters adapters, not blind SQL replacement.
4. Verify public rewrites (retain existing `destination` archive path), hidden languages, translation clusters, site-specific Product Activation, filters, listings, SEO, caching and frontend.
5. Four site-specific readbacks and independent release decisions; Production remains separately authorized rather than forbidden.

Do not assume four legacy sites share the same migration need. Per-object WPML identity gaps (four terms plus thirty independent tours on ETG) belong to Reviews and must never be auto-connected by matching title or numeric ID.
