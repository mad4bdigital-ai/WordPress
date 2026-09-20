# Research and Verified Decisions

## Bundled vendor versions inspected
- JetSmartFilters `3.8.3.1`
- JetEngine `3.8.11.2`
- Rank Math SEO `1.0.275`
- Rank Math PRO `3.0.118`
- WPML Multilingual CMS `4.9.6`
- WPML SEO `2.2.5`
- WPML String Translation `3.5.3`

## R1 — JetSmartFilters provider identity
Verified `get_current_provider('provider')` and `get_current_provider('query_id')`. Runtime identity uses exact normalized equality; substring matching is not authority.

## R2 — JetEngine custom query mapping
Bundled JetEngine exposes Query Builder `custom_query_ids_mapping` and `Manager::get_query_by_id()`. A JetSmartFilters custom query ID can therefore resolve the corresponding Query Builder object through the bundled API surface.

## R3 — Filtered result-count lifecycle
Bundled JetEngine/JetSmartFilters applies `jet_smart_filters()->query->get_query_from_request()` values using `set_filtered_prop()` and obtains the total using `get_items_total_count()`. JetEngine exposes that total to JetSmartFilters as `found_posts`.

**Decision:** the built-in count adapter clones the resolved Query Builder object, sets up the query, applies the current filtered properties, and reads `get_items_total_count()`. If filtered request state is unavailable, result authority is unavailable. Unfiltered taxonomy/post counts are never substituted.

## R4 — Query Builder Post Type authority (verified probe)
A dedicated repository probe inspected the exact bundled JetEngine `3.8.11.2` Posts Query source. GitHub Actions run `33885606509` completed successfully on the temporary staging probe.

Verified surfaces:
- class `Jet_Engine\\Query_Builder\\Queries\\Posts_Query`,
- public `get_query_args()`,
- `get_query_args()` defaults `post_type` to `any` when the query does not bind one,
- public `get_query_type()` inherited from Base Query,
- public Query Builder properties include `query_type` and `query_id`,
- Posts Query uses the final configured/filtered `post_type` in WP_Query arguments.

**Decision:** an Elementor page's main WP query is not sufficient Post Type authority for a JetEngine listing. For JetEngine profiles, `query_builder` is the safe default authority. The selected exact Query Builder must be type `posts`; `post_type=any` is unbounded/unobserved; and every observed Post Type must be within the Profile allowlist. A mixed `property + product` query therefore fails a `property`-only Profile.

## R5 — Exact routes, not provider/query Cartesian products
Separate provider and query allowlists can accidentally authorize an unconfigured pair as the system grows. New Profiles therefore use exact `{provider,query_id}` routes. Legacy Cartesian behavior is retained only inside the explicit inherited migration profile.

## R6 — Archive path authority
Matching only the final archive slug is insufficient for nested paths, translated paths, or two surfaces that share a suffix. Generic Profiles require exact Unicode-safe archive paths. A known active WPML language prefix may wrap a configured base authority; arbitrary prefixes cannot.

## R7 — Discovery is not authorization
WordPress can register new Post Types/taxonomies dynamically through themes/plugins. Automatically turning discovered objects into SEO Profiles would create uncontrolled index growth.

**Decision:** discovery is read-only. Blueprint generation is disabled/non-authorizing and leaves routes, structural sets, and exact combinations empty.

## R8 — Profile-scoped taxonomy policy
A taxonomy can be attached to multiple Post Types with different SEO/business semantics. Global taxonomy rules would leak authority across surfaces.

**Decision:** taxonomy role, display order, single-index permission, thresholds, business meta constraints, and term field maps are Profile-scoped.

## R9 — Term field maps and galleries
Native term meta and ACF can use site-specific keys. Requiring code changes per taxonomy is incompatible with controlled dynamic growth.

**Decision:** each TaxonomyRule can prepend a bounded `field_map` for canonical fields (`seo_title`, `meta_description`, `focus_keyword`, `short_description`, `image`, `gallery`, `location_level`). Multiple gallery sources are aggregated and deduplicated.

## R10 — Monotonic authority under extension hooks
Configuration and content hooks are extension points and can otherwise bypass sanitization/hard gates.

**Decision:** filtered configuration/Profile data is normalized again. Content readiness extensions are veto-only: they may make a ready page unready, but cannot promote an incomplete page to ready. Attempts are recorded.

## R11 — Unique content evidence
Generated intro can reuse the same Term text that also appears in Term descriptions. Counting both inflates apparent uniqueness.

**Decision:** readiness measures a deduplicated content corpus; duplicate segments do not increase content length evidence.

## R12 — Rank Math
Verified frontend title/description/canonical/robots filters. Rank Math constructs the OpenGraph image hook dynamically as `opengraph/{$network}/image`; Facebook resolves to `rank_math/opengraph/facebook/image`.

## R13 — WPML
Verified `wpml_current_language`, `wpml_object_id`, active/default language surfaces. Explicit preview language is derived only from known active WPML language prefixes. Archive-path normalization remains Unicode-safe.

## R14 — Simulation boundary
Synthetic simulation is useful for deterministic policy objections but cannot prove actual WordPress registrations, JetEngine listing lifecycle, rendered HTML, WPML output, or cache behavior.

**Decision:** Scenario Lab always emits `synthetic=true` and is never sufficient staging/production acceptance evidence.

## R15 — Caching
Persistent cross-request decision caching remains disabled in Alpha. Correct invalidation must account for profile/config revision, term/meta edits, WPML translations, Query Builder changes, result inventory changes, and plugin/vendor lifecycle.


## Query Builder inventory source verification — 2026-09-04
An isolated exact-vendor probe confirmed bundled JetEngine exposes `Jet_Engine\Query_Builder\Manager::instance()->get_queries()`, plus `get_query_by_id()` and `get_queries_for_options()`. Runtime inventory therefore uses `get_queries()` and reads only each query object's structural `id`, `query_id`, `get_query_type()` and `get_query_args()`-derived Post Type/taxonomy names. Raw args are not exported.


## Inventory reconciliation research conclusions
- Runtime discovery needs a second gate before Profile editing: evidence can be valid but stale relative to configured authority.
- Native CPT archive URLs cannot be treated as the sole listing-surface identity because Elementor/JetEngine commonly render CPT listings on Pages; mismatch is therefore review evidence, not an independent hard deny.
- Query Builder custom IDs and bounded Post Type sets are stronger route evidence than crawler-visible query strings, but discovered routes remain non-authorizing.
- Snapshot fingerprints are useful only if recomputed and collection bounds are enforced on import.
- Automatic rollback on blocking drift is intentionally excluded: fail-closed indexing authority and operational rollback are separate operator-controlled actions.
- Dynamic growth is safest when new surfaces are represented as disabled candidates whose authority fields remain empty, while discovered archive/query values are stored separately as evidence suggestions.
