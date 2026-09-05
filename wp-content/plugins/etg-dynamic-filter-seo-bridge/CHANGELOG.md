# Changelog

## 0.4.0-alpha.7

- Reworked the WordPress admin surface into task-focused tabs: Overview, Configuration, Discovery, Runtime Inventory, Reconciliation, URL Inspector and Scenario Lab.
- Added persistent Global bridge/readiness/config-revision/profile-count status cards so the master safety state remains visible on every tab.
- Added visible `?` explainers to every configuration selector and operational action button, including the impact of Production-affecting settings and the read-only/non-authorizing boundary of evidence tools.
- Added explicit READ-ONLY and SYNTHETIC badges to evidence and simulation surfaces.
- Lazy-loads discovery, runtime inventory, reconciliation, URL inspection and simulation only on their relevant tabs instead of performing all page work on every admin request.
- Added a compact Runtime Inventory evidence summary for contract, completeness and availability errors before the full JSON payload.
- Corrected the migrated Tours default taxonomy from `tour-style_jet` to the Production-observed `tour-styles_jet` in parser compatibility, structural taxonomy sets and the style taxonomy rule.
- Did not widen authority to other discovered Production taxonomies and did not infer Query Builder/Post Type authority from discovery alone.
- Global bridge remains default OFF; merge and Production activation remain unauthorized.

## 0.4.0-alpha.6

- Added explicit runtime source availability evidence for Post Types, taxonomies, WPML languages, Query Builder and translated archive paths.
- Added fail-closed `etg.dfsb.runtime-inventory-unavailable.v1` for partial snapshots when any mandatory evidence source is unavailable, invalid, or exception-producing.
- Kept valid Staging evidence on `etg.dfsb.runtime-inventory.v2`; normal snapshots now declare `evidence_complete=true` and an empty `availability_errors` list.
- Prevented unavailable Query Builder sources from being misrepresented as an observed empty query inventory while preserving valid empty arrays as available evidence.
- Prevented unavailable/invalid WPML active-language evidence from being interpreted as a complete zero-language runtime.
- Prevented the native/current archive URL from being duplicated under language keys when `wpml_permalink` authority is unavailable or invalid.
- Unavailable snapshots are rejected by reconciliation as `invalid_inventory`, cannot generate disabled candidates, and cannot close T120/T121.
- Added source-availability and reconciliation rejection regression coverage across PHP 7.4 and current PHP.
- No profile is auto-enabled, no Staging evidence is inferred, and Production activation remains unauthorized.

## 0.4.0-alpha.5

- Removed legacy Cartesian authority synthesis from global inheritance: inherited provider/query arrays, taxonomy sets and exact combinations no longer create route or structural authority implicitly.
- Made post-filter profile overflow fail visibly and fail closed with `filtered_profile_count_limit_exceeded`; truncated profile registries cannot authorize routes.
- Made registry validation independent of call order so normalization errors are visible on the first validation/readiness path.
- Added `structural_key` as the final Query Builder deterministic-order tie-breaker, keeping fingerprints stable when otherwise-identical query identities differ structurally.
- Clarified the legacy `index_single_tour_type` migration boundary: it may set the taxonomy rule's `index_single` policy, but it cannot synthesize `allowed_taxonomy_sets`; structural authority must be explicit in the profile.
- Added pre-Staging regression coverage for inherited-authority denial, filtered profile overflow, order-independent validation, deterministic query collisions and fail-closed route resolution.
- Updated governance inspection to follow the modular Runtime Inventory implementation across `RuntimeInventory.php`, `RuntimeInventoryQueryTrait.php` and `RuntimeInventoryStructureTrait.php`.
- No profile is auto-enabled, no Staging evidence is inferred, and Production activation remains unauthorized.

## 0.4.0-alpha.4

- Upgraded runtime inventory to `etg.dfsb.runtime-inventory.v2` with explicit completeness records (`observed_count`, `included_count`, `limit`, `truncated`) for Post Types, taxonomies, languages, Query Builder queries and archive-path translations.
- Sorts Query Builder identities before bounded slicing so equivalent runtime inventories remain deterministic regardless of manager return order.
- Detects duplicate effective Query Builder identities (custom query ID or stored ID fallback), records bounded conflict evidence, and prevents collided IDs from becoming exact route authority.
- Upgraded reconciliation to `etg.dfsb.inventory-reconciliation.v2`; truncated sections are blocking evidence and never produce false `missing` or `removed` conclusions.
- Disabled candidate generation now requires a complete, available and collision-free inventory.
- Snapshot comparison skips incomplete or ambiguous sections instead of inferring removals from truncated evidence.
- Added overflow, collision, unavailable-query and completeness-tamper regression coverage.

## 0.4.0-alpha.3

- Added bounded read-only inventory reconciliation (`etg.dfsb.inventory-reconciliation.v1`).
- Detects missing/drifted Post Types, taxonomies, archive evidence, Query Builder identities, boundedness and language paths without mutating profiles.
- Rejects invalid, oversized or fingerprint-tampered runtime inventory snapshots.
- Treats CPT archive paths as evidence only because Elementor/JetEngine listing surfaces may live on Pages.
- Generates disabled candidate profiles for newly discovered Post Types while leaving archive authority, exact routes, taxonomy sets and combinations empty.
- Suggested Query Builder routes remain evidence-only until explicitly copied and reviewed by an operator.
- Added read-only WP-CLI commands: `wp etg-dfsb inventory` and `wp etg-dfsb reconcile --previous=<file>`.
- Added realistic drift/reconciliation smoke coverage and controlled-growth objections.

## 0.4.0-alpha.2

- Added capability-gated, read-only runtime inventory export (`etg.dfsb.runtime-inventory.v1`).
- Inventories public Post Types, taxonomy relations, WPML active languages/archive paths, and exact JetEngine Query Builder structural identities.
- Query inventory uses the verified bundled `Manager::instance()->get_queries()` API and never exports raw query arguments.
- Added fail-closed drift scenarios for taxonomy slug, provider/query route, query-string URL grammar, language inventory, and mixed Post Type observations.
- No discovered item auto-enables or mutates a Surface Profile.

## 0.4.0-alpha.1 — Generic Surface Profile Engine
- replaces travel-only scope/policy assumptions with bounded Surface Profiles;
- adds exact archive-path and provider+query route authorities;
- adds query-builder-first Post Type authority with fail-closed `any`, non-post, mixed/outside Post Types and optional `main_query|either|both` modes;
- adds generic taxonomy roles, structural taxonomy-set authority, per-taxonomy thresholds and term-meta constraints;
- adds per-taxonomy configurable field maps for SEO/content/image/gallery sources;
- aggregates multiple configured gallery fields with normalized attachment IDs;
- adds generic content/gallery/breadcrumb ordering while retaining legacy travel composition mode;
- adds non-authorizing Post Type/taxonomy discovery and disabled Profile Blueprint generation;
- adds non-mutating Synthetic Scenario Lab for realistic objection testing;
- makes new profiles default disabled and rejects silent duplicate profile IDs;
- requires exact archive paths and exact route pairs for new non-legacy profiles;
- makes archive authority Unicode-safe and WPML-prefix-aware without arbitrary suffix matching;
- preserves the previous valid profile snapshot when submitted profile JSON is invalid or exceeds Alpha bounds;
- re-normalizes configuration/profile extension filters and makes content-readiness hooks veto-only;
- prevents Rank Math/Elementor presentation mutation when profile/provider/Post Type/translation identity is invalid;
- deduplicates content-readiness corpus so repeated term copy is not counted twice;
- expands realistic simulations and CI contract tests for generic growth.

## 0.3.0-alpha.1 — Operational hardening
- default activation changed to disabled;
- hard fail-closed decisions separated from soft policy overrides;
- exact language-aware combination registry added;
- content-readiness authority added;
- legacy numeric result counts made non-authoritative by default;
- built-in JetEngine Query Builder filtered result-count adapter added from verified vendor lifecycle;
- provider/query comparison changed to exact normalized identity;
- URL query-state, tracking, pagination, duplicate and multi-value governance added;
- WPML preview language inference and language-prefix-safe archive canonical added;
- non-Arabic/non-English content composition made neutral;
- term SEO title now participates in metadata;
- runtime readiness expanded with capability and taxonomy lifecycle checks;
- admin operational settings/preview expanded;
- permanent CI specification expanded to PHP 7.4/current + vendor drift + provenance.

## 0.2.0-alpha.1 — Operational foundation
- configuration authority, readiness, preview, decision logging, canonical modes, result-count contract.

## 0.1.0 — MVP
- JetSmartFilters URL parsing, WPML term resolution, term metadata composition, Elementor shortcodes, Rank Math metadata, conservative indexing policy.
