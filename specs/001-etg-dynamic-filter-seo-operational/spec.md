# Feature Specification — ETG Dynamic Filter SEO Bridge Generic Operationalization

**Branch:** `feat/etg-dynamic-filter-seo-bridge`
**Baseline:** MVP `0.1.0` at `e405568858f540119d33d9fa6d690918cb26b1e1`
**Previous exact-head operational alpha:** `d033e60b82319c03358df2ec23f39b09f361d8fc`
**Target:** `0.4.0-alpha.4` Generic Surface Profile Engine
**Merge authorized:** No
**Production activation authorized:** No

## Problem
The hardened Tours implementation is safe but not truly generic while runtime semantics still assume a travel-specific trio. Editing an allowlist is insufficient if roles, Post Type identity, route matching, content sources, taxonomy shape, thresholds, gallery sources and presentation remain coupled to one archive.

The Generic Surface Profile Engine makes these dimensions explicit configuration authorities while retaining strict fail-closed behavior. It is designed for additional WordPress Post Types/taxonomies such as Real Estate, Products/Catalog, Knowledge/Resources or future CPTs without requiring a PHP patch for each new taxonomy.

## Operational State Machine
`request → parse → exact archive/profile authority → exact provider+query route → runtime/provider observation → Post Type authority → runtime readiness → term/WPML resolution → taxonomy role/meta authority → taxonomy-set authority → exact combination authority → content readiness → filtered result-count authority → hard safety gates → soft threshold → presentation/Rank Math → bounded diagnostics`

## Core Principle: Discovery Is Not Authorization
Read-only discovery and disabled blueprint generation are operator aids only. They MUST NOT write/enable profiles, add taxonomy shapes, approve combinations, or change indexing state.

Controlled growth:
`discover → disabled blueprint → configure exact authorities → simulate objections → readiness → staging evidence → explicit enable → later RC/merge authorization`.

## P1 User Stories

### US1 Global and per-profile safety
- Global kill switch defaults OFF.
- New profiles default disabled if `enabled` is omitted.
- Disabled profiles preserve vendor behavior.
- Invalid submitted Profile JSON preserves the previous valid snapshot.

### US2 Exact profile isolation
- One known archive + wrong provider/query remains governed and fails closed.
- Taxonomy/Post Type from another profile cannot bleed into the selected profile.
- Duplicate/ambiguous profile authorities degrade readiness and runtime fails closed.

### US3 Exact archive authority
- New profiles use exact `archive_paths[]`.
- Known WPML language prefixes may wrap a base exact path.
- Explicit translated Unicode paths are supported.
- Arbitrary path suffixes do not match.

### US4 Exact route authority
- New profiles use exact `routes[{provider,query_id}]`.
- Independent provider/query arrays are legacy migration fallback only.
- Provider/query Cartesian authorization is forbidden for new profiles.

### US5 Post Type authority
- `query_builder` is the safe default when Post Type binding is required.
- Exact JetEngine Query Builder custom query must be `posts` and expose bounded final Post Type(s).
- `any`, non-post query, missing authority, mixed allowed+foreign types or outside types hard-deny.
- `main_query`, `either`, `both` are explicit operator modes for special surfaces.

### US6 Generic taxonomy policy
Each taxonomy may configure role, content/gallery priority, single-taxonomy indexing permission, minimum result threshold, term-meta constraint, and a canonical field map.

### US7 Dynamic term content/gallery mapping
A new taxonomy can map its existing native/ACF term keys to `seo_title`, `meta_description`, `short_description`, `focus_keyword`, `image`, `gallery`, and `location_level` without PHP changes. Multiple gallery fields aggregate deterministically.

### US8 Structural taxonomy-set authority
Known taxonomies remain non-indexable in unapproved shapes. Profiles explicitly allow order-independent taxonomy sets.

### US9 Exact combination authority
Where required, combinations are profile+language+taxonomy+slug bound. High result count never substitutes for exact editorial authority.

### US10 Deduplicated content readiness
Repeated term content is counted once. A content-readiness extension can veto a ready page but cannot promote failed base content into ready.

### US11 Generic presentation
`generic` mode orders arbitrary roles by profile priority for H1/sections/breadcrumb/gallery. `travel` mode preserves ETG behavior. Metadata/shortcodes are blocked on invalid provider/Post Type/translation identity.

### US12 Synthetic Scenario Lab
Operators can model changes such as wrong query, foreign taxonomy, Post Type drift, term-meta state, translation fallback and inventory drops using the same IndexingPolicy without mutating WordPress.

### US13 Disabled Profile Blueprint
Discovery can create a non-authorizing JSON starting point for one Post Type and taxonomies actually attached to it. The blueprint starts disabled and intentionally leaves archive/routes/shapes/combinations incomplete.

### US14 Controlled bounded growth
Alpha limits are explicit: 50 profiles, 20 exact routes/profile, 50 taxonomy rules/profile, 5,000 exact combinations/profile. Exceeding bounds fails visibly or preserves prior authority; it is never silently treated as full authorization.

### US15 Runtime inventory reconciliation
Operators can compare bounded runtime evidence with configured Profiles and an optional prior snapshot. Drift is classified without mutation, and newly discovered surfaces may only produce disabled evidence-backed candidates.

### US16 Release provenance
Every candidate is bound to exact source SHA, PHP matrix, vendor blob SHAs, test suites and deterministic installable ZIP digest.

## Functional Requirements
- FR-001 Vendor plugins remain immutable.
- FR-002 Global default `enabled=false`.
- FR-003 New profile default is disabled when `enabled` is absent.
- FR-004 Profile IDs are unique; duplicates are configuration errors.
- FR-005 New non-legacy profiles require exact archive paths.
- FR-006 Archive paths are Unicode-safe; known WPML prefix wrapping is allowed, arbitrary suffix matching is not.
- FR-007 New non-legacy profiles require exact provider+query route pairs.
- FR-008 Known profile surfaces with provider/query/taxonomy mismatch remain governed and hard-deny.
- FR-009 Required Post Type binding defaults to Query Builder authority.
- FR-010 Post Type match requires every observed type to be inside the allowlist.
- FR-011 `post_type=any`, non-post Query Builder query, missing query or unobserved authority hard-deny.
- FR-012 Taxonomy roles are unique within a profile.
- FR-013 Allowed taxonomy sets are normalized/order-independent and must only reference profile taxonomies.
- FR-014 Taxonomy field maps are bounded and canonical-key-only.
- FR-015 Multiple gallery fields aggregate and deduplicate normalized attachment IDs.
- FR-016 Optional taxonomy meta constraints are hard authority.
- FR-017 Exact combination registry is profile-aware and language-aware.
- FR-018 Filtered authoritative result count is required by default; no unfiltered fallback.
- FR-019 Content readiness is profile-specific and uses unique copy segments.
- FR-020 Content readiness hook is veto-only.
- FR-021 Metadata/shortcodes require valid scope, provider observation, required Post Type binding, resolved terms and translation identity.
- FR-022 Generic breadcrumb/gallery/content order follows profile priorities.
- FR-023 Invalid/oversized `profiles_json` preserves previous valid authority.
- FR-024 Profile/config extension filters are re-normalized.
- FR-025 Discovery and blueprint generation are read-only/non-authorizing.
- FR-026 Synthetic simulation is non-mutating and marked synthetic.
- FR-027 Persistent cross-request cache remains disabled until invalidation evidence exists.
- FR-028 CI runs all legacy + generic + authority + presentation + field-map smoke suites on PHP 7.4 and current PHP.
- FR-029 CI re-verifies exact JetEngine/JetSmartFilters/Rank Math/WPML capability surfaces.
- FR-030 Merge and Production activation remain separate explicit gates.
- FR-031 Reconciliation recomputes runtime-inventory fingerprints and rejects tampered/oversized snapshots.
- FR-032 Enabled-profile runtime identity loss or Query Builder boundedness downgrade is blocking drift.
- FR-033 Native CPT archive-path mismatch is warning evidence only; it cannot independently invalidate an Elementor/Page listing surface.
- FR-034 New discovered Post Types may yield disabled candidates only; exact archive/routes/shapes/combinations remain empty until operator review.
- FR-035 WP-CLI inventory/reconcile operations are read-only and previous-file input is bounded.

## Realistic Objections That Must Stay Closed
1. Allowed taxonomy ≠ index permission.
2. Discovery ≠ profile creation or activation.
3. One archive slug ≠ unique surface authority.
4. Provider list + query list ≠ safe route pair.
5. Main Elementor page Post Type ≠ JetEngine Listing Post Type.
6. One allowed type inside a mixed query ≠ valid Post Type authority.
7. `post_type=any` ≠ evidence.
8. High result count ≠ content/combination authority.
9. Synthetic green ≠ staging green.
10. Invalid configuration submission must not erase the previous authority snapshot.
11. Repeated copy must not inflate content readiness.
12. A metadata hook must not mutate a page whose structural identity is already invalid.
13. A public/new taxonomy must not automatically expand allowed structural sets.
14. New field keys must be explicitly mapped rather than inferred from arbitrary term meta.
15. A changed inventory fingerprint must not be trusted without recomputation.
16. Native CPT archive URL != Elementor listing Page authority.
17. Suggested route evidence != configured exact route.
18. Blocking drift must not trigger automatic profile mutation.

## Non-Goals for `0.4.0-alpha.4`
- Automatic profile activation/generation
- Traffic/crawler-derived combination approval
- Persistent custom registry database
- Persistent cross-request decision cache
- Sitemap/indexable-URL registry
- Clean SEO URL migration
- Schema expansion
- Production activation

## GA Gates
Every profile intended for activation must pass real staging Post Type/taxonomy/relation inventory, exact archive/route binding, representative term-field/translation checks, filtered-result equality, objection scenarios, rendered Rank Math/Elementor output, cache/kill-switch rollback rehearsal, exact-head CI/provenance, and separate merge authorization.


## Runtime Inventory Acceptance Enablement

The plugin SHALL provide an administrator-only, read-only runtime inventory (`etg.dfsb.runtime-inventory.v2`) that records bounded structural Post Type/taxonomy relations, WPML language/archive-path observations and JetEngine Query Builder identities. The inventory MUST be non-authorizing, MUST omit raw query arguments, MUST not mutate options/profiles, and MUST not be treated as staging certification by itself.


## Inventory Reconciliation & Controlled Growth

`0.4.0-alpha.4` adds `etg.dfsb.inventory-reconciliation.v2`. It compares the current bounded runtime inventory with normalized configured Profiles and optionally a previous inventory snapshot. The output is always non-authorizing and read-only. It may produce disabled candidate Profiles for newly discovered Post Types, but authority-bearing fields stay empty. Archive paths and exact Query Builder routes remain evidence until explicitly reviewed and configured.

Operational growth becomes:
`inventory → verify fingerprint/bounds → reconcile drift → disabled evidence candidate → operator binds exact archive/route/taxonomy sets/combinations → simulate → staging acceptance → explicit enable`.


## Inventory completeness and collision safety
The runtime inventory SHALL distinguish bounded inclusion from complete observation using explicit completeness metadata for every bounded section. Reconciliation MUST fail closed on truncation, MUST NOT infer `missing`/`removed` from truncated evidence, and MUST suppress candidate generation when inventory is incomplete. Effective Query Builder identities MUST be deterministic and collision-aware; duplicate effective IDs MUST be blocking and MUST never satisfy an exact route.
