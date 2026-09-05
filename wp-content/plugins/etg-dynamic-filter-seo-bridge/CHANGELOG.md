# Changelog

## 0.4.0-alpha.10

- Clarified plugin update identity: a WordPress “This plugin is already installed” screen with **Replace current with uploaded** is the expected same-plugin upgrade path. Added a checksum/structure-validated maintenance fallback only for hosts where the standard replacement action is unavailable.
- Added runtime release identity (`release-identity.json`) and provenance v3 binding version, Git SHA, tree SHA and deterministic source-manifest SHA256.
- Bound Rank Math title/description/canonical/social mutation to the final positive indexing decision; blocked URLs retain original Rank Math metadata while ETG may still force `noindex`.
- Made `etg_filter_seo_should_index`, configured combination filters, allowed-taxonomy filters, configuration filters and Surface Profile filters narrowing/veto-only so extension code cannot silently create indexing authority.
- Added contract + explicit source allowlist for any external authoritative result count; legacy numeric counts remain untrusted by default.
- Bound provider, Elementor and count-parity evidence to both profile authority fingerprint and plugin version; verified evidence now requires immutable `sha256:<64hex>` refs only.
- Added a non-authorizing **Live Runtime Probe** that captures provider/query timing, request-count availability, WPML/Unicode state, lifecycle stage, query/memory timing and SHA256-only server HTML evidence across real frontend requests.
- Runtime Inventory now fails `evidence_complete` on any truncation or Query Builder identity collision, not only on source availability errors.
- Hardened background count `tax_query` composition to preserve nested existing groups and made publication-count extension hooks veto-only.
- Added Unicode-filter diagnostics for live WPML validation; translated slugs still require real runtime evidence and fail closed on missing terms/fallback.
- Prevented an indexable filtered URL from using `canonical_mode=archive`; `clean_filtered` remains deferred.
- Added publication URL collision suppression, version-bound publication cache keys, ACF/Elementor cache invalidation hooks, and a separate cold-start candidate evaluation budget.
- Reduced fresh-install/default rollout to 10 live URLs per global/profile authority, preview to 25, with recommended 10 → 25 → 50 → 100 expansion only after runtime/performance evidence.
- Added bounded rollout controls and Live Runtime Probe tabs to the Publication admin surface.
- Upgraded Evidence Bundle to v2 with release identity and runtime-probe summary while remaining read-only and non-authorizing.
- Preserved `merge_authorized=false` and `production_activation_authorized=false`; Production activation still requires live runtime evidence.

## 0.4.0-alpha.9

- Converted Alpha8 publication review objections into executable fail-closed governance without authorizing merge or Production indexing.
- Built-in Tours profile now defaults `enabled=false`; Global bridge still defaults OFF. Disabled-profile evidence resolution is non-authorizing and cannot be reused as live request authority.
- Added provider/query observation gates. Live requests hard-deny unobserved provider state as `provider_query_unobserved` and mismatched observed identity as `provider_query_mismatch`; background publication requires recorded provider-observation evidence.
- Added result-count parity governance. When required, sitemap publication needs evidence that frontend rendered count, request-time authoritative adapter count and publication background probe count are equal.
- Made the background Query Builder count probe WPML-language aware: required language switching uses `SitePress::switch_lang()`, `suppress_filters=false`, restores the previous language in `finally`, and fails closed when language context/switching is unavailable.
- Preserves Query Builder base `tax_query` constraints and combines ETG exact taxonomy filters under an explicit outer `AND`.
- Hardened content readiness with default minimum content floors of 250/400/500 characters by depth and optional minimum unique Term-content segments; insufficient unique content fails as `insufficient_unique_sections`.
- Reduced publication authority bounds: maximum stored exact combinations per profile is 500; live publication candidates hard-cap at 500; default global and per-profile rollout ceiling is 100; Admin preview defaults to 50 and hard-caps at 100.
- Added bounded persistent publication-candidate transient caching keyed by cache epoch, configuration revision, profile/signature and preview/publication mode. Relevant content/config changes bump the epoch; cache remains non-authorizing.
- Added Publication Candidate v2 separation between `metadata_candidate`, `metadata_emitted_if_global_on`, `schema_candidate`, `schema_emitted` and `sitemap_included`.
- Added Structured Profile Manager under Settings → ETG SEO Publication for safe daily editing of profile state, exact single route, taxonomy sets, approved combinations, provider evidence, Elementor evidence, parity evidence, sitemap enablement and rollout bounds.
- Structured Profile Manager writes require `manage_options`, nonce validation and Global OFF. Verified flags require non-empty evidence IDs. Multiple-route profiles remain Advanced JSON to avoid destructive flattening.
- Added read-only `etg.dfsb.publication-evidence-bundle.v1` with readiness, Runtime Inventory, publication preview, cache/performance metrics, profile evidence references, activation blockers and required external runtime evidence.
- Added `Profile Manager` and `Evidence Bundle` tabs to the publication Admin surface.
- Kept `clean_filtered` canonical routing deliberately deferred until rewrite/collision/WPML/canonical/redirect/JetSmartFilters runtime evidence exists; only `filtered` and `archive` remain available.
- Updated release documentation to Alpha9 boundaries and explicitly retained `merge_authorized=false` and `production_activation_authorized=false`.
- Static CI remains insufficient for runtime acceptance; real WordPress evidence is still required for inventory completeness, provider observation, Elementor server-side content, Rank Math output, WPML behavior, count parity, sitemap behavior and performance.

## 0.4.0-alpha.8

- Added governed SEO Publication layer for approved dynamic JetSmartFilters URLs without creating WordPress Page/Post records.
- Added a Rank Math `etg-filter-seo` sitemap provider with native sitemap-index pagination and optional gallery images.
- Live ETG sitemap publication is empty while the Global bridge is OFF and only includes URLs whose final `IndexingPolicy` decision is `index=true`.
- Publication URL generation now requires profile-bound, language-bound exact combination signatures; legacy language-only signatures cannot become sitemap authority.
- Added a request-independent JetEngine Query Builder publication result-count probe that preserves the exact query base arguments, adds exact taxonomy filters and fails closed on unbounded/non-post/missing query state.
- Added sitemap freshness invalidation for posts, object-term relations, Term create/edit/delete, Term-meta add/update/delete and ETG configuration changes.
- Expanded Rank Math publication metadata to OpenGraph URL/type/title/description/image, Twitter title/description/image/card type and `CollectionPage` JSON-LD while retaining title, description, canonical and robots integration.
- Added WPML dynamic hreflang publication: translated alternate URLs are emitted only when all Terms translate without fallback and the target language has its own exact approved profile-bound combination; approved default language may provide `x-default`.
- Added a separate Settings → ETG SEO Publication admin with Overview, Candidates, Elementor Content and Sitemap & Discovery tabs.
- Added read-only Publication Candidate Preview with per-URL metadata, result-count evidence, content readiness, Elementor verification, sitemap state and explicit exclusion reasons.
- Added Elementor Theme Builder Term-content shortcodes: `etg_filter_term` and `etg_filter_term_section`, alongside the existing combined H1/intro/sections/gallery/breadcrumb shortcodes.
- Added explicit Elementor publication gating: when `require_elementor_content=true`, `elementor_content_verified=false` hard-denies indexing with `elementor_content_not_verified`.
- Added optional `elementor_render_when_global_off=true` dark-presentation mode so Term content can be visibly validated in Elementor on real filter URLs while Rank Math/index/sitemap authority remains OFF.
- Added non-authorizing evidence context resolution used only for dark validation/publication planning; it cannot bypass live provider/query/Post Type failures after Global is enabled.
- Added publication capability readiness for Rank Math Sitemap Provider/Router/Cache and WPML active-language/permalink sources; Elementor Pro is required when enabled profiles require Theme Builder content.
- Added Alpha8 governance contracts for SEO publication and Elementor content publication plus dedicated publication smoke coverage on PHP 7.4/current.
- Corrected the fallback parser taxonomy to the Production-observed `tour-styles_jet` everywhere; the stale singular `tour-style_jet` is no longer fallback parser authority.
- No Production activation, route inference or automatic combination approval is authorized by this release.

## 0.4.0-alpha.7

- Reworked the WordPress admin surface into task-focused tabs: Overview, Configuration, Discovery, Runtime Inventory, Reconciliation, URL Inspector and Scenario Lab.
- Added persistent Global bridge/readiness/config-revision/profile-count status cards so the master safety state remains visible on every tab.
- Added visible `?` explainers to every configuration selector and operational action button, including the impact of Production-affecting settings and the read-only/non-authorizing boundary of evidence tools.
- Added explicit READ-ONLY and SYNTHETIC badges to evidence and simulation surfaces.
- Lazy-loads discovery, runtime inventory, reconciliation, URL inspection and simulation only on their relevant tabs instead of performing all page work on every admin request.
- Added a compact Runtime Inventory evidence summary for contract, completeness and availability errors before the full JSON payload.
- Corrected the migrated Tours default taxonomy from `tour-style_jet` to the Production-observed `tour-styles_jet` in parser compatibility, structural taxonomy sets and the style taxonomy rule.
- Did not widen authority to other discovered Production taxonomies and did not infer Query Builder/Post Type authority from discovery alone.

## 0.4.0-alpha.6

- Added explicit runtime-source availability evidence for Post Types, taxonomies, WPML languages, Query Builder and translated archive paths.
- Added fail-closed `etg.dfsb.runtime-inventory-unavailable.v1` for incomplete mandatory runtime evidence.
- Prevented unavailable Query Builder/WPML sources from being misrepresented as trustworthy empty inventories.
- Kept Runtime Inventory/Reconciliation read-only and non-authorizing.

## 0.4.0-alpha.5

- Removed legacy Cartesian route-authority synthesis from global inheritance.
- Made profile overflow and normalization errors fail visibly and fail closed.
- Kept exact taxonomy-set authority explicit and Query Builder collision ordering deterministic.

## 0.4.0-alpha.4

- Added Runtime Inventory v2 completeness/truncation evidence and Query Builder identity-collision detection.
- Added Reconciliation v2 and suppressed candidate generation for incomplete/ambiguous inventory.

## 0.4.0-alpha.3

- Added bounded read-only inventory reconciliation, drift evidence and disabled growth candidates.
- Added WP-CLI inventory/reconciliation commands.

## 0.4.0-alpha.2

- Added capability-gated runtime inventory for public Post Types, taxonomies, WPML and Query Builder identities.

## 0.4.0-alpha.1 — Generic Surface Profile Engine

- Replaced travel-only authority assumptions with bounded generic Surface Profiles.
- Added exact archive/routes/Post Type/taxonomy-set/combination authority and configurable Term field maps.
- Added disabled profile blueprints, Scenario Lab and presentation identity guards.

## 0.3.0-alpha.1 — Operational hardening

- Default activation changed to disabled and hard fail-closed indexing gates were separated from soft policy overrides.
- Added exact language-aware combinations, content readiness, authoritative result counts and provider/query identity checks.

## 0.2.0-alpha.1 — Operational foundation

- Added configuration authority, readiness, preview, decision logging, canonical modes and result-count contract.

## 0.1.0 — MVP

- Initial JetSmartFilters URL parsing, WPML Term resolution, Term metadata composition, Elementor shortcodes and Rank Math metadata integration.
