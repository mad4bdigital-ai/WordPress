# Implementation Plan — Generic Surface Profile Engine

## Architecture
1. `FilterUrlParser` owns JetSmartFilters URL grammar and query-state classification.
2. `ProfileRegistry` owns bounded Surface Profiles, exact archive/route authority, taxonomy rules, discovery and disabled blueprints.
3. `RequestScope` resolves one exact profile or returns neutral/hard-invalid scope evidence.
4. `PostTypeObserver` owns Post Type authority from exact JetEngine Query Builder and optional main-query modes.
5. `Readiness` owns dependency/capability/profile/Post-Type/taxonomy-relation checks.
6. `LanguageResolver` owns explicit URL language and WPML term translation.
7. `TermMetaReader` owns normalized term data plus per-taxonomy configurable field maps and multi-gallery aggregation.
8. `ContentComposer`/`GalleryComposer` own travel or generic ordered presentation.
9. `CombinationRegistry` owns profile/language/taxonomy/slug exact permission.
10. `ContentReadiness` owns deduplicated content evidence with veto-only extension semantics.
11. `JetEngineResultCountAdapter` + `ResultCountResolver` own filtered result authority.
12. `IndexingPolicy` owns structural hard safety vs final soft threshold policy.
13. `MetadataAdapter`/shortcodes mutate presentation only after structural identity is valid.
14. `ScenarioSimulator` reuses the real policy for bounded non-mutating objection testing.
15. `DecisionLogger` and Admin UI expose bounded evidence.

## Verified Vendor Lifecycle
Bundled JetEngine 3.8.11.2 and JetSmartFilters 3.8.3.1 were inspected from repository ZIPs. Query Builder Manager resolves custom query IDs. JetEngine applies JetSmartFilters request props through `set_filtered_prop()` and exposes `get_items_total_count()` as filtered count authority. Posts Query additionally exposes public `get_query_args()` and `get_query_type()`; the final `post_type` becomes the safe default Post Type authority for profile-bound JetEngine listings.

## Safety Design
- Global bridge and new profiles default disabled.
- Discovery/blueprints never authorize.
- New profiles require exact archive paths and exact route pairs.
- No arbitrary archive suffix matching.
- No provider/query Cartesian fallback for new profiles.
- Post Type authority is query-builder-first and rejects `any`, non-post, mixed/outside types.
- Taxonomy knowledge, allowed shapes and exact combinations are separate authorities.
- Invalid profile JSON preserves previous valid authority.
- Content authority is monotonic; extension hooks may veto but not promote failed content.
- No unfiltered count fallback.
- No persistent cache in Alpha.

## Delivery Strategy
- Preserve MVP commit `e4055688…`.
- Fold all operational/generic work into one clean operational commit above MVP.
- Replace only the feature ref; never mutate `master`.
- Keep PR Draft and update exact-head evidence.
- CI validates exact source SHA, PHP 7.4/8.3, vendor capabilities, all smoke/simulation suites, Spec Kit and deterministic package provenance.
- Live staging profile inventory/acceptance remains required before RC/merge.


## T120 enablement
Use the read-only Runtime Inventory Export first on the intended staging WordPress instance. Compare its snapshot fingerprint and structural identities against public reconnaissance, then construct disabled candidate profiles. No discovered route/taxonomy/language may be promoted automatically.


## Alpha3 — Inventory Reconciliation & Controlled Growth
1. Validate current Runtime Inventory contract, limits and recomputed fingerprint.
2. Compare current Profiles against runtime Post Type/taxonomy/query evidence without mutation.
3. Compare optional prior snapshot and classify structural drift.
4. Emit disabled candidates only for sufficiently evidenced new Post Types; preserve empty authority-bearing fields.
5. Offer admin download and WP-CLI read-only evidence paths.
6. Keep staging acceptance T120–T129 unresolved until evidence comes from the intended staging runtime.
