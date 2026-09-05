# ETG Dynamic Filter SEO Bridge

Operational Alpha `0.4.0-alpha.9` is a governed Surface Profile Engine and SEO publication layer for JetSmartFilters + JetEngine filtered archives with WPML, Elementor Theme Builder and Rank Math.

It does not create synthetic WordPress Pages for filter combinations. It resolves exact filtered URL state at runtime, composes visible Term-driven archive content, applies guarded Rank Math metadata, and can publish only explicitly approved/indexable dynamic URLs through a Rank Math sitemap provider.

Vendor source is never edited.

## Alpha9 safety model

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
  "max_preview_urls": 50,
  "max_publication_urls": 100
}
```

Verification flags require non-empty evidence IDs. Evidence IDs are references only; they must be backed by real runtime acceptance records.

## Exact combination authority

A publishable dynamic page must come from an exact profile- and language-bound signature, for example:

```text
tours|en|location_jet=cairo|tour-types_jet=day-tours
properties|it|property_city=roma|property_type=hotel
```

No wildcard, traffic-derived approval or Cartesian authority is used.

Alpha9 hard-caps stored exact combinations at **500 per profile**. Publication evaluation is also hard-capped at **500 live candidates**. The default global rollout ceiling is **100**, the default per-profile publication ceiling is **100**, the default Admin preview is **50**, and Admin preview is hard-capped at **100**.

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

For language-bound publication, Alpha9 enters the requested WPML language context when required, sets `suppress_filters=false`, restores the previous language in `finally`, and fails closed when WPML language context or language switching is unavailable.

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

Content readiness uses deduplicated Term-derived content. Alpha9 defaults are deliberately conservative minimums:

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

Alpha9 Publication Candidate v2 separates candidate state from emitted state:

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

Alpha9 uses a bounded transient candidate cache keyed by cache epoch, configuration revision, profile/signature and preview/publication mode.

Relevant post, taxonomy, Term-meta and ETG configuration changes bump the cache epoch. Rank Math sitemap cache invalidation remains in place.

The cache is an optimization only. It cannot create authority or turn an excluded candidate into an included candidate.

## WPML hreflang

A target language is emitted only when every selected Term resolves to a real translation without fallback, the translated slugs form their own exact profile-bound approved combination, and the target dynamic URL independently passes publication/index policy.

Missing language authority is omitted rather than fabricated. `x-default` may point to the approved default-language dynamic URL.

## Admin operations

### Settings → ETG Filter SEO

Operational diagnostics remain available for configuration, discovery, Runtime Inventory, reconciliation, URL inspection and scenario testing. Runtime Inventory/Reconciliation remain read-only and non-authorizing.

### Settings → ETG SEO Publication

Alpha9 tabs are:

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

`etg.dfsb.publication-evidence-bundle.v1` is read-only and non-authorizing. It packages readiness, Runtime Inventory, publication preview, cache/performance metrics, profile evidence references, activation blockers and required external runtime evidence.

It always states:

```text
merge_authorized=false
production_activation_authorized=false
```

The bundle cannot substitute for real WordPress runtime evidence.

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

Alpha9 intentionally does **not** activate `clean_filtered` canonical routing. Clean public URLs remain deferred until runtime evidence proves route collisions, rewrite behavior, translated permalink behavior, canonical equivalence, redirects and JetSmartFilters compatibility.

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

It also verifies Rank Math sitemap Provider/Router/Cache capability surfaces, WPML language/permalink publication sources, Alpha9 governance locks, bounded publication behavior and release provenance.

## WP-CLI diagnostics

```bash
wp etg-dfsb inventory > runtime-inventory.json
wp etg-dfsb reconcile --previous=runtime-inventory.previous.json > reconciliation.json
```

## Runtime acceptance still required

Static CI does not prove WordPress runtime acceptance. Before Ready for Review or any Production activation decision, collect real evidence for:

1. Runtime Inventory v2 completeness.
2. Exact Query Builder/Post Type authority.
3. Provider/query observation on representative filtered requests.
4. Server-rendered Elementor H1/intro/Term sections in source HTML.
5. Rank Math title/description/canonical/robots/OG/Twitter/schema output.
6. EN/AR/third-language translated slug and hreflang behavior.
7. Frontend/request/background result-count parity.
8. Global OFF empty live ETG sitemap.
9. Bounded Global ON sitemap inclusion of approved/indexable URLs only.
10. Sitemap TTFB, query count, cache behavior and memory baseline.

## Operational boundary

Alpha9 does **not** authorize merge or Production activation by release alone.

It also does not infer Production Query Builder authority from discovery, auto-approve taxonomy permutations, create synthetic WordPress Pages, treat sitemap discovery as indexing authority, or treat Elementor template existence as automatic verification.

Exact runtime evidence and a separate explicit activation decision remain operator-controlled.
