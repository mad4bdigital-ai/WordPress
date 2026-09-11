# ETG Dynamic Filter SEO Bridge

Operational Alpha `0.4.0-alpha.10` is a governed Surface Profile Engine and SEO publication layer for JetSmartFilters + JetEngine filtered archives with WPML, Elementor Theme Builder and Rank Math.

It does not create synthetic WordPress Pages for filter combinations. It resolves exact filtered URL state at runtime, composes visible Term-driven archive content, applies guarded Rank Math metadata, and can publish only explicitly approved/indexable dynamic URLs through a Rank Math sitemap provider.

Vendor source is never edited.

## Alpha10 safety model

The Global bridge defaults **OFF** and the built-in Tours profile defaults **disabled**.

```text
discover
→ inspect Runtime Inventory
→ reconcile evidence
→ configure exact profile authority
→ add/import Term content
→ dark-render Elementor presentation if required
→ verify server-rendered content
→ record provider/query observation evidence
→ record frontend/request/background count-parity evidence
→ approve exact language-bound combinations
→ preview candidates + Evidence Bundle
→ separate explicit Global enable decision
```

It is never:

```text
discover → Cartesian-generate every taxonomy combination → auto-index
```

Global OFF means Rank Math metadata mutation, robots index authority, hreflang publication and the live ETG sitemap remain off. Read-only diagnostics, evidence resolution and publication preview remain non-authorizing.

Disabled-profile evidence resolution may inspect an exact profile during dark validation, but it cannot be reused as live authority. Live request evaluation still requires Global ON and an enabled profile.

## Surface Profiles

`profiles_json` is the advanced authoritative representation. A profile may define:

- `post_types[]`
- `require_post_type_binding`
- `post_type_authority`: `query_builder|main_query|either|both`
- exact `archive_paths[]`
- exact `routes[]` of `{provider, query_id}`
- `require_provider_observation_for_index`
- `taxonomy_rules{}`
- `allowed_taxonomy_sets[]`
- exact `indexable_combinations[]`
- per-depth result thresholds
- per-depth content thresholds
- per-depth unique content-segment thresholds
- canonical mode
- `travel|generic` composition mode
- publication policy and evidence references

Default Tours publication policy includes:

```json
{
  "sitemap": true,
  "hreflang": true,
  "schema": true,
  "social": true,
  "include_images_in_sitemap": true,
  "require_elementor_content": true,
  "elementor_render_when_global_off": false,
  "elementor_content_verified": false,
  "elementor_verification_evidence_id": "",
  "provider_observation_verified": false,
  "provider_observation_evidence_id": "",
  "require_result_count_parity_for_publication": true,
  "result_count_parity_verified": false,
  "result_count_parity_evidence_id": "",
  "max_preview_urls": 25,
  "max_publication_urls": 10
}
```

Verification flags require immutable `sha256:<64hex>` evidence references. Alpha10 rejects free-form evidence/ticket labels for verified state. Evidence is also bound to the normalized profile authority fingerprint **and plugin version**, so route/taxonomy/combination/content-governance changes or a plugin upgrade make prior evidence stale.

## Exact combination authority

A publishable dynamic page must come from an exact profile- and language-bound signature, for example:

```text
tours|en|location_jet=cairo|tour-types_jet=day-tours
properties|it|property_city=roma|property_type=hotel
```

No wildcard, traffic-derived approval or Cartesian authority is used.

Alpha10 hard-caps stored exact combinations at **500 per profile**. The hard live publication ceiling remains 500, but the default global and per-profile rollout ceiling is now **10 URLs**, the default Admin preview is **25**, and the default cold-start candidate evaluation budget is **50**. Recommended controlled expansion is **10 → 25 → 50 → 100** only after live evidence and performance review.

## Provider/query observation authority

A structurally valid URL is not enough to grant indexing authority.

For live requests:

- `provider_observed=false` hard-denies with `provider_query_unobserved`;
- an observed provider/query that differs from the exact profile route hard-denies with `provider_query_mismatch`.

For background publication evaluation, missing provider evidence hard-denies with `provider_observation_not_verified`.

The exact Query Builder identity must remain bounded to a Posts query and bounded Post Types. `post_type=any`, missing queries, non-post queries and Post Types outside profile authority fail closed.

## Result-count authority and parity

The request-time adapter mirrors the authoritative JetSmartFilters filtered request state.

The publication background probe resolves the exact configured JetEngine Query Builder query, preserves its base args, adds exact taxonomy filters and performs a bounded `WP_Query` count. It never substitutes an unfiltered archive count.

For language-bound publication, Alpha10 enters the requested WPML language context when required, sets `suppress_filters=false`, restores the previous language in `finally`, and fails closed when WPML language context or language switching is unavailable.

Where `require_result_count_parity_for_publication=true`, sitemap publication requires recorded proof that:

```text
frontend rendered listing count
== request-time authoritative adapter count
== publication background probe count
```

A mismatch or missing evidence blocks publication.

## Elementor Theme Builder content

The same resolved translated Term context used for SEO metadata can be rendered as server-side HTML in Elementor.

Combined presentation shortcodes:

```text
[etg_filter_h1]
[etg_filter_intro]
[etg_filter_sections]
[etg_filter_gallery]
[etg_filter_gallery mode="priority" limit="8" size="large"]
[etg_filter_keyword]
[etg_filter_breadcrumb_context]
```

Individual Term fields:

```text
[etg_filter_term role="location" field="name"]
[etg_filter_term role="location" field="description" autop="1"]
[etg_filter_term role="tour_type" field="short_description" autop="1"]
[etg_filter_term role="style" field="description" autop="1"]
[etg_filter_term taxonomy="location_jet" field="description" autop="1"]
```

Semantic Term sections:

```text
[etg_filter_term_section role="location" field="description" heading="1" heading_level="2"]
[etg_filter_term_section role="tour_type" field="description" heading="1" heading_level="2"]
```

A profile may explicitly set:

```json
"elementor_render_when_global_off": true
```

This is presentation-only dark validation. It does not authorize Rank Math metadata, `index`, hreflang or sitemap publication.

When `require_elementor_content=true`, indexing is hard-denied until `elementor_content_verified=true` and a non-empty `elementor_verification_evidence_id` are recorded after real server-rendered validation.

## Term content source of truth

Standard taxonomy `description` is read from the translated `WP_Term`. Term SEO/content/image fields can also be mapped through each taxonomy rule's `field_map`, including existing JetEngine or ACF Term meta.

Content readiness uses deduplicated Term-derived content. Alpha10 defaults are deliberately conservative minimums:

- global fallback: 250 characters;
- depth 1: 250 characters;
- depth 2: 400 characters;
- depth 3: 500 characters.

Profiles may additionally require minimum unique Term-content segments by depth. Failure is explicit as `insufficient_unique_sections`. These thresholds are minimum gates, not a guarantee of editorial quality.

## Rank Math metadata publication

For an eligible structurally resolved dynamic URL, ETG integrates with Rank Math for:

- frontend title
- meta description
- canonical
- robots `index|noindex` + `follow`
- OpenGraph type and URL
- Facebook title/description/image
- Twitter title/description/image/card type
- JSON-LD `CollectionPage`

Alpha10 Publication Candidate v2 separates candidate state from emitted state:

- `metadata_candidate`
- `metadata_emitted_if_global_on`
- `schema_candidate`
- `schema_emitted`
- `sitemap_included`

Schema and emitted metadata do not become live merely because a preview can compose them.

## Dynamic Rank Math sitemap

ETG registers an `etg-filter-seo` sitemap provider with Rank Math.

Expected first sitemap URL:

```text
/etg-filter-seo-sitemap.xml
```

The live provider returns no ETG URLs while Global is OFF.

A URL enters the live sitemap only when its final indexing decision is `index=true` and every configured profile, route, taxonomy-set, exact combination, translated Term, content, Elementor, provider-observation, result-count and count-parity gate passes.

Up to five priority gallery images may accompany a sitemap URL when enabled.

## Persistent publication candidate cache

Alpha10 uses a bounded transient candidate cache keyed by cache epoch, configuration revision, profile/signature and preview/publication mode.

Relevant post, taxonomy, Term-meta and ETG configuration changes bump the cache epoch. Rank Math sitemap cache invalidation remains in place.

The cache is an optimization only. It cannot create authority or turn an excluded candidate into an included candidate.

## WPML hreflang

A target language is emitted only when every selected Term resolves to a real translation without fallback, the translated slugs form their own exact profile-bound approved combination, and the target dynamic URL independently passes publication/index policy.

Missing language authority is omitted rather than fabricated. `x-default` may point to the approved default-language dynamic URL.

## Admin operations

### Settings → ETG Filter SEO

Operational diagnostics remain available for configuration, discovery, Runtime Inventory, reconciliation, URL inspection and scenario testing. Runtime Inventory/Reconciliation remain read-only and non-authorizing.

### Settings → ETG SEO Publication

Alpha10 tabs are:

- Overview
- Candidates
- Profile Manager
- Elementor Content
- Sitemap & Discovery
- Evidence Bundle

### Structured Profile Manager

The structured surface manages daily operational profile fields without requiring raw JSON editing:

- profile enabled state
- exact single route
- allowed taxonomy sets
- exact approved combinations
- provider observation evidence
- Elementor dark-render and verification evidence
- result-count parity evidence
- sitemap enablement
- preview/publication bounds

Writes require `manage_options` and nonce validation and are **blocked while Global bridge is ON**. Multiple-route profiles remain Advanced JSON for route editing to avoid destructive flattening. Raw JSON remains the advanced authoritative representation.

### Evidence Bundle

`etg.dfsb.publication-evidence-bundle.v2` is read-only and non-authorizing. It packages readiness, Runtime Inventory, publication preview, cache/performance metrics, profile evidence references, activation blockers and required external runtime evidence.

It always states:

```text
merge_authorized=false
production_activation_authorized=false
```

The bundle cannot substitute for real WordPress runtime evidence.

## Alpha10 Live Runtime Probe

Settings → ETG SEO Publication → **Live Runtime Probe** can be armed for a short 10-minute observation window. It is non-authorizing and records only bounded technical evidence for real filtered frontend requests:

- provider/query observation at `wp`, `template_redirect`, early/late `wp_head`, and shutdown;
- request-time result count source and authoritative state;
- WPML language, missing terms, translation fallback and Unicode-filter diagnostics;
- query count, elapsed time and memory;
- final server HTML SHA256 plus presence of ETG server-rendered sections, robots and canonical evidence.

The full HTML, IP address, cookies and request body are not retained. Probe records expose immutable `sha256:` evidence refs that can be used by the Profile Manager after operator review. The probe does not prove frontend/request/background count parity by itself; parity still requires a reviewed three-way comparison artifact.

## Runtime Inventory and reconciliation

Runtime Inventory v2 inventories public Post Types, taxonomy relations, WPML language evidence, translated archive paths, JetEngine Query Builder structural identities, identity collisions and completeness/truncation evidence.

Eligible inventory must declare:

```text
contract=etg.dfsb.runtime-inventory.v2
evidence_complete=true
availability_errors=[]
```

Unavailable mandatory sources return `etg.dfsb.runtime-inventory-unavailable.v1` and cannot grant authority. Reconciliation remains non-mutating and cannot auto-enable profiles or copy discovered routes into authority.

## Canonical URL boundary

Alpha10 intentionally does **not** activate `clean_filtered` canonical routing. Clean public URLs remain deferred until runtime evidence proves route collisions, rewrite behavior, translated permalink behavior, canonical equivalence, redirects and JetSmartFilters compatibility.

Only the proven `filtered` and `archive` canonical modes remain available.

## Verified bundled compatibility surfaces

CI verifies the repository-bundled capability surfaces for:

- JetSmartFilters 3.8.3.1
- JetEngine 3.8.11.2
- Rank Math SEO 1.0.275
- Rank Math SEO PRO 3.0.118
- WPML Multilingual CMS 4.9.6
- WPML SEO 2.2.5
- WPML String Translation 3.5.3

It also verifies Rank Math sitemap Provider/Router/Cache capability surfaces, WPML language/permalink publication sources, Alpha10 governance locks, bounded publication behavior and release provenance.

## WP-CLI diagnostics

```bash
wp etg-dfsb inventory > runtime-inventory.json
wp etg-dfsb reconcile --previous=runtime-inventory.previous.json > reconciliation.json
```

## Plugin identity and uploaded ZIP upgrades

The plugin directory and primary plugin file intentionally stay stable across Alpha releases. Therefore, when WordPress upload shows **“This plugin is already installed”** and offers **“Replace current with uploaded”**, that is the expected upgrade flow and confirms WordPress recognizes the new ZIP as the same plugin. Use that standard WordPress replacement path first.

Settings → ETG SEO Maintenance provides a checksum/structure-validated fallback only for a host where the standard replacement action is unavailable. The fallback is locked while Global Bridge is ON and rejects mixed-root, traversal, oversized, wrong-identity, same-version and downgrade packages.

## Runtime acceptance still required

Static CI does not prove WordPress runtime acceptance. Before Ready for Review or any Production activation decision, collect real evidence for:

1. Runtime Inventory v2 completeness with no truncation and no Query Builder identity collision.
2. Provider/query timing: identify the first deterministic lifecycle stage where the real JetSmartFilters provider/query is observed.
3. Request-time authoritative result count on representative filtered URLs.
4. Three-way count parity: frontend rendered listing = request adapter = background publication probe, including a Query Builder query with an existing nested `tax_query`.
5. Elementor content present in **View Source/server HTML**, not only the post-JavaScript DOM.
6. WPML translated and Unicode slugs with `missing_terms=[]`, `translation_fallback=false`, correct hreflang and `x-default`.
7. Global OFF produces no ETG live sitemap URLs.
8. Controlled Global ON validation uses the bounded 10-URL phase first, then 25/50/100 only after review.
9. Sitemap cold-start/cached TTFB, DB query count and memory baseline.
10. Cache invalidation after real Term/ACF/Elementor content edits.
11. Runtime release identity matches the exact package provenance.
## Operational boundary

Alpha10 does **not** authorize merge or Production activation by release alone.

It also does not infer Production Query Builder authority from discovery, auto-approve taxonomy permutations, create synthetic WordPress Pages, treat sitemap discovery as indexing authority, or treat Elementor template existence as automatic verification.

Exact runtime evidence and a separate explicit activation decision remain operator-controlled.


Live Rank Math title, description, canonical and social metadata are emitted only after the complete indexing policy returns `index=true`; blocked URLs keep the site's original Rank Math metadata while ETG can still force `noindex`.
