# ETG Dynamic Filter SEO Bridge

Operational Alpha `0.4.0-alpha.10` is a governed Surface Profile Engine and SEO publication layer for JetSmartFilters + JetEngine filtered archives with WPML, Elementor Theme Builder and Rank Math.

It does not create synthetic WordPress Pages for filter combinations. It resolves exact filtered URL state at runtime, composes visible Term-driven archive content, applies guarded Rank Math metadata, and can publish only explicitly approved/indexable dynamic URLs through a Rank Math sitemap provider.

Vendor source is never edited.

## Alpha10 safety model

The Global bridge defaults **OFF** and the built-in Tours profile defaults **disabled**. Alpha10 additionally makes the JetSmartFilters custom Query ID namespace explicit: route `query_id` values are resolved only against JetEngine Query Builder objects' custom `query_id`, never against internal numeric record IDs.

```text
discover
→ inspect Runtime Inventory
→ reconcile evidence
→ configure exact profile authority
→ resolve exact custom Query ID uniquely
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

## JetEngine Query identity boundary

Profile and JetSmartFilters route identity is the custom Query ID. Alpha10 resolves it from `Jet_Engine\Query_Builder\Manager::instance()->get_queries()` and requires exactly one object whose `query_id` matches. The object's internal `id` is returned only as diagnostic evidence.

Missing custom IDs fail as `query_identity_not_found`, duplicate exact custom IDs fail as `query_identity_ambiguous`, and unavailable query inventory fails as `query_identity_inventory_unavailable`. Numeric-looking custom IDs are never treated as internal Query Builder IDs.

The same resolver is shared by Post Type observation, request-time authoritative result counts and background publication result counts.

## Surface Profiles

`profiles_json` is the advanced authoritative representation. A profile may define `post_types[]`, `require_post_type_binding`, `post_type_authority`, exact `archive_paths[]`, exact `routes[]` of `{provider, query_id}`, taxonomy rules/sets, exact language-bound combinations, result/content thresholds, canonical mode, composition mode and publication/evidence policy.

Verification flags require non-empty evidence IDs. Evidence IDs are references only; they must be backed by real runtime acceptance records.

## Exact combination authority

A publishable dynamic page must come from an exact profile- and language-bound signature, for example:

```text
tours|en|location_jet=cairo|tour-types_jet=day-tours
properties|it|property_city=roma|property_type=hotel
```

No wildcard, traffic-derived approval or Cartesian authority is used. Stored exact combinations and publication candidate evaluation remain hard-capped at 500 per profile/evaluation, with lower configurable preview/publication rollout ceilings.

## Provider/query observation authority

A structurally valid URL is not enough to grant indexing authority. Live requests hard-deny unobserved provider state as `provider_query_unobserved`; mismatched observed identity hard-denies as `provider_query_mismatch`. Background publication requires recorded provider-observation evidence.

The exact custom Query Builder identity must resolve uniquely to a Posts query and bounded Post Types. `post_type=any`, missing queries, duplicate custom IDs, non-post queries and Post Types outside profile authority fail closed.

## Result-count authority and parity

The request-time adapter mirrors the authoritative JetSmartFilters filtered request state. The background probe resolves the same exact custom Query ID, preserves Query Builder base args, adds exact taxonomy filters and performs a bounded `WP_Query` count. It never substitutes an unfiltered archive count.

For language-bound publication, the probe enters the requested WPML language context when required, sets `suppress_filters=false`, restores the previous language in `finally`, and fails closed when WPML language context or language switching is unavailable.

Where result-count parity is required, sitemap publication needs recorded proof that frontend rendered listing count equals request-time authoritative adapter count and background publication count.

## Elementor Theme Builder content

The same resolved translated Term context used for SEO metadata can be rendered as server-side HTML in Elementor using the ETG combined and Term-level shortcodes. Optional `elementor_render_when_global_off=true` remains presentation-only dark validation and does not authorize Rank Math metadata, `index`, hreflang or sitemap publication.

When `require_elementor_content=true`, indexing is hard-denied until Elementor server-rendered content is verified with a non-empty evidence ID.

## Rank Math metadata publication

For an eligible structurally resolved dynamic URL, ETG integrates with Rank Math for frontend title, meta description, canonical, robots, OpenGraph, Twitter metadata and JSON-LD `CollectionPage`.

Publication Candidate v2 separates candidate state from emitted state through `metadata_candidate`, `metadata_emitted_if_global_on`, `schema_candidate`, `schema_emitted` and `sitemap_included`.

## Dynamic Rank Math sitemap

ETG registers an `etg-filter-seo` sitemap provider. Expected first sitemap URL:

```text
/etg-filter-seo-sitemap.xml
```

The live provider returns no ETG URLs while Global is OFF. A URL enters the live sitemap only when its final indexing decision is `index=true` and every configured profile, route, custom Query identity, taxonomy-set, exact combination, translated Term, content, Elementor, provider-observation, result-count and parity gate passes.

## Persistent publication candidate cache

The bounded transient candidate cache is keyed by cache epoch, configuration revision, profile/signature and preview/publication mode. Relevant content/config changes bump the epoch. The cache is an optimization only and cannot create authority.

## WPML hreflang

A target language is emitted only when every selected Term resolves to a real translation without fallback, translated slugs form their own exact approved combination, and the target dynamic URL independently passes publication/index policy. Missing language authority is omitted rather than fabricated.

## Admin operations

Settings → ETG Filter SEO contains configuration, discovery, Runtime Inventory, reconciliation, URL inspection and scenario diagnostics. Runtime Inventory/Reconciliation remain read-only and non-authorizing.

Settings → ETG SEO Publication contains Overview, Candidates, Profile Manager, Elementor Content, Sitemap & Discovery and Evidence Bundle. Structured writes require `manage_options` and nonce validation and are blocked while Global is ON.

The read-only `etg.dfsb.publication-evidence-bundle.v1` packages readiness, Runtime Inventory, publication preview, cache/performance metrics, profile evidence references, activation blockers and required external runtime evidence. It cannot substitute for real WordPress runtime evidence.

## Runtime Inventory and reconciliation

Runtime Inventory v2 inventories public Post Types, taxonomy relations, WPML language evidence, translated archive paths, JetEngine Query Builder internal IDs plus custom Query IDs, identity collisions and completeness/truncation evidence. Reconciliation remains non-mutating and cannot auto-enable profiles or copy discovered routes into authority.

## Canonical URL boundary

Alpha10 intentionally does **not** activate `clean_filtered` canonical routing. Clean public URLs remain deferred until runtime evidence proves route collisions, rewrite behavior, translated permalink behavior, canonical equivalence, redirects and JetSmartFilters compatibility. Only `filtered` and `archive` canonical modes remain available.

## Verified bundled compatibility surfaces

CI verifies repository-bundled JetSmartFilters, JetEngine, Rank Math and WPML capability surfaces, plus custom Query-ID inventory resolution, publication governance locks, bounded behavior and release provenance.

## WP-CLI diagnostics

```bash
wp etg-dfsb inventory > runtime-inventory.json
wp etg-dfsb reconcile --previous=runtime-inventory.previous.json > reconciliation.json
```

## Runtime acceptance still required

Static CI does not prove WordPress runtime acceptance. Before Ready for Review or any Production activation decision, collect real evidence for Runtime Inventory completeness, the exact Tours custom Query ID and bounded Post Type, provider observation, Elementor server-rendered content, Rank Math output, WPML behavior, frontend/request/background count parity, Global-OFF empty ETG sitemap, bounded Global-ON sitemap behavior, and performance.

## Operational boundary

Alpha10 does **not** authorize merge or Production activation by release alone. It does not infer Production Query Builder authority from discovery, auto-approve taxonomy permutations, create synthetic WordPress Pages, treat sitemap discovery as indexing authority, or treat Elementor template existence as automatic verification.

`merge_authorized=false`

`production_activation_authorized=false`

Exact runtime evidence and a separate explicit activation decision remain operator-controlled.
