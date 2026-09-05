# Changelog

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
