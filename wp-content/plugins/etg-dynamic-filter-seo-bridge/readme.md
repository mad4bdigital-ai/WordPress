# ETG Dynamic Filter SEO Bridge

Operational Alpha `0.4.0-alpha.13` is a governed Dynamic Presentation + SEO publication bridge for JetSmartFilters + JetEngine filtered archives with Elementor, WPML and Rank Math.

It does not create synthetic WordPress Pages for filter combinations and it does not make AJAX filter state an indexing authority. It resolves exact filtered runtime state, exposes governed Elementor Dynamic Tags and Content Slots, composes visible Term-driven content and media, and keeps Rank Math/indexing/sitemap publication behind separate fail-closed evidence gates.

Vendor source is never edited.

## Alpha13 operating model

```text
JetSmartFilters  -> filter/runtime state only
JetEngine        -> query/listing/relations/CCT source authority
WPML             -> language and translation authority
Elementor        -> presentation layer
Rank Math        -> SEO output layer
ETG Bridge       -> governance, context, composition, diagnostics and publication gates
```

The bridge deliberately separates two concerns:

```text
presentation state != SEO/indexing authority
```

A filter change may update visible Elementor content through the presentation runtime while remaining completely non-authorizing for canonical, robots, hreflang, schema or sitemap publication.

## Safety model

The Global bridge defaults **OFF** and the built-in Tours profile defaults **disabled**.

AJAX presentation responses always remain non-authorizing:

```text
authorizing=false
url_authority=false
seo_mutation=false
state_transport=ajax
```

The intended governance flow is:

```text
discover
→ inspect Runtime Inventory
→ reconcile evidence
→ configure exact profile authority
→ resolve exact custom Query ID uniquely
→ prepare Dynamic Tags / Content Slots / archive presentation
→ dark-render presentation when permitted
→ verify server-rendered Elementor content
→ record provider/query observation evidence
→ record frontend/request/background result-count parity evidence
→ approve exact language-bound combinations
→ preview candidates + Evidence Bundle
→ separate explicit Global enable decision
```

It is never:

```text
discover → Cartesian-generate every taxonomy combination → auto-index
```

Global OFF means Rank Math metadata mutation, robots index authority, hreflang publication and the live ETG sitemap remain off. Read-only diagnostics, presentation resolution, evidence collection and publication preview remain non-authorizing.

## Elementor Dynamic Tags

Elementor Dynamic Tags are the primary authoring path. Shortcodes remain available as fallback helpers and for non-Elementor or special live-media cases.

The Alpha13 runtime includes governed tags for:

- Filter Title
- Filter Intro
- Filter Result Summary
- Filter Keyword
- Filter Archive URL
- Filter Current URL
- Term Field / Term Section
- Term Meta Value
- Filter Image / Image URL
- Filter Gallery / Slideshow
- Content Slot text / URL / image / gallery
- Inventory/runtime values where exposed by the Runtime Inventory catalog

Elementor tag controls are category-aware: text, URL, image and gallery tags are registered only for compatible Elementor Dynamic Tag control categories.

## Dynamic Content Slots

Content Slots are the reusable composition layer. They let developers/admins prepare governed presentation recipes while content editors consume simple named Dynamic Tags inside Elementor.

Recommended operating pattern:

```text
Developer/Admin prepares slots
→ Content editor selects ETG Content Slot tags
→ Runtime resolves approved sources
→ Elementor renders presentation
```

Typical reusable slot IDs include:

```text
hero_title
hero_intro
hero_image
results_summary
location_section
tour_type_section
style_section
related_links
faq_block
```

Slots are presentation-only. Creating or rendering a slot does not enable a profile and does not grant SEO publication authority.

## JetEngine integration

ETG does not replace JetEngine's listing/query renderer. JetEngine remains the renderer/query engine while ETG supplies context, bounded sources and composed presentation values.

Supported source families include the governed surfaces exposed by the current runtime, including:

- Listing context fields/meta
- Query Builder sources
- Repeaters
- Relations and relation meta
- Posts / Terms / Users hydration
- CCT sources
- Media/image/gallery sources
- Runtime Inventory topology values

Source discovery is bounded and guarded against recursion. Runtime Inventory and JetEngine Inspector remain diagnostic/non-authorizing surfaces.

## JetEngine Query identity boundary

Profile and JetSmartFilters route identity is the **custom Query ID**, not the internal numeric Query Builder record ID.

Alpha13 preserves case-sensitive custom Query IDs and resolves them against JetEngine Query Builder inventory. Internal numeric IDs are diagnostic evidence only.

Missing custom IDs fail closed as `query_identity_not_found`, duplicate exact custom IDs fail closed as `query_identity_ambiguous`, and unavailable query inventory fails closed as `query_identity_inventory_unavailable`.

Numeric-looking custom Query IDs are never treated as internal Query Builder IDs.

The same identity contract is shared across runtime provider observation, request-time authoritative result counts, background publication counts and presentation group identity.

## JetSmartFilters AJAX presentation runtime

Alpha13 adds a presentation-only REST bridge:

```text
POST /wp-json/etg-dfsb/v1/ajax-presentation
```

The endpoint is public because filtered archive visitors need it, but it is bounded and fail-closed for presentation state:

- bounded JSON payload
- bounded requested token count
- bounded requested slot count
- allowlisted runtime tokens
- enabled-slot allowlist
- exact provider/query context checks
- unknown/malformed filter rejection
- missing-Term and translation-fallback rejection
- no browser-history mutation
- `Cache-Control: no-store`
- `X-Robots-Tag: noindex, nofollow, noarchive`
- HTTP `429` when the built-in burst limiter is active and exceeded

### AJAX rate-protection boundary

The built-in cross-request burst limiter requires a persistent WordPress object cache. With a persistent cache it uses a bounded per-IP bucket without writing per-visitor transients/database state.

Without a persistent object cache the runtime reports:

```text
external_waf_required
```

That state is acceptable for staging/presentation validation but **must not be treated as complete Production activation evidence by itself**. Before Production activation, prove one of:

- persistent object-cache rate protection, or
- external WAF / reverse-proxy / server rate limiting for the endpoint.

## Archive Hero preset and Container Dynamic Background

Alpha13 contains a presentation-only archive Hero generalization layer and container-owned dynamic background runtime.

Opt-in marker:

```text
etg-dfsb-archive-hero
```

Wrapper kill switch:

```text
etg-dfsb-background-off
```

The archive Hero preset only normalizes in-memory Elementor render settings. It performs no persistent Elementor write.

For marker-enabled Containers without an explicitly saved ETG background mode, conservative defaults are applied through the existing governed media engine, including slideshow/balanced collection, Wide Hero suitability, bounded media count and motion-safe defaults.

Explicit `Elementor Default` remains a hard OFF override. The kill-switch class wins over stale historical ETG background configuration on the marked wrapper.

The runtime remains container-owned and paint-contained; it does not create body/page-level slideshow ownership. `prefers-reduced-motion` remains respected.

## Term Meta Dynamic Tag boundary

`ETG Term Meta Value` is inventory-driven rather than an arbitrary meta-key text box.

The Runtime Inventory catalog:

- discovers bounded Term meta keys
- keeps keys exact/case-sensitive through the governed field-key contract
- excludes sensitive-looking keys such as credentials/tokens/secrets
- excludes non-renderable/non-scalar values from selector discovery

The Elementor selector only exposes cataloged `termmeta:<role>:<key>` tokens. AJAX requests for Term-meta values must also pass the same catalog allowlist. Runtime value resolution returns scalar values only.

## Surface Profiles

`profiles_json` is the advanced authoritative representation. A profile may define:

- bounded Post Types
- Post Type binding requirements
- exact archive paths
- exact `{provider, query_id}` routes
- taxonomy rules and allowed taxonomy sets
- exact language-bound combinations
- result/content thresholds
- canonical mode
- composition mode
- publication/evidence policy

For normal operation, prefer task-focused Admin UI surfaces over editing Advanced JSON directly.

## Exact combination authority

A publishable dynamic page must come from an exact profile- and language-bound signature, for example:

```text
tours|en|location_jet=cairo|tour-types_jet=day-tours
properties|it|property_city=roma|property_type=hotel
```

No wildcard, traffic-derived approval or Cartesian authority is used. Stored combinations and publication candidate evaluation remain bounded.

## Provider/query observation authority

A structurally valid URL is not enough to grant indexing authority.

Live requests hard-deny unobserved provider state as `provider_query_unobserved`; mismatched observed identity hard-denies as `provider_query_mismatch`. Background publication requires recorded provider-observation evidence.

The exact custom Query Builder identity must resolve uniquely to a Posts query and bounded Post Types. `post_type=any`, missing queries, duplicate custom IDs, non-post queries and Post Types outside profile authority fail closed.

## Result-count authority and parity

The request-time adapter mirrors the authoritative JetSmartFilters filtered request state. The background probe resolves the same exact custom Query ID, preserves Query Builder base args, applies exact taxonomy filters and performs a bounded count.

For language-bound publication, the probe enters the required WPML language context, uses filter-aware queries and restores the previous language in `finally`.

Where parity is required, publication evidence must prove:

```text
frontend rendered count
= request-time authoritative adapter count
= background publication count
```

## Elementor server-rendered publication evidence

Presentation working in the Elementor editor or after a browser-side AJAX update is not enough to grant indexing authority.

When `require_elementor_content=true`, indexing remains hard-denied until server-rendered HTML is verified with external runtime evidence.

Editor preview is synthetic evidence. Live direct filtered URLs must be inspected separately.

## Rank Math metadata publication

For an eligible structurally resolved dynamic URL, ETG can integrate with Rank Math for frontend title, description, canonical, robots, OpenGraph, Twitter metadata and CollectionPage JSON-LD.

This output remains behind Global/profile/evidence/indexing gates. AJAX state never mutates Rank Math output directly.

## Dynamic Rank Math sitemap

ETG registers an `etg-filter-seo` sitemap provider. Expected first sitemap URL:

```text
/etg-filter-seo-sitemap.xml
```

The live provider returns no ETG URLs while Global is OFF.

A URL enters the live sitemap only when its final indexing decision is `index=true` and every configured profile, route, custom Query identity, taxonomy-set, exact combination, translated Term, content, Elementor, provider-observation, result-count and parity gate passes.

## WPML hreflang

A target language is emitted only when every selected Term resolves to a real translation without fallback, translated slugs form their own approved exact combination, and the target dynamic URL independently passes publication/index policy.

Missing language authority is omitted rather than fabricated.

## Admin product surfaces

The Alpha13 product separates operational responsibilities across task-focused surfaces, including:

- Control Center
- Dynamic Content
- Media Lab
- JetEngine Inspector
- Inventory Control
- SEO Publication
- Usage Guide

Recommended responsibility split:

```text
Content Editor -> Elementor Dynamic Tags + Usage Guide
SEO Manager    -> SEO Publication + Evidence Bundle + approved combinations
Developer      -> JetEngine Inspector + Dynamic Content source/slot design
Admin/Operator -> Control Center + Inventory Control + safety boundary
```

## Runtime Inventory and reconciliation

Runtime Inventory inventories bounded Post Types, taxonomy relations, WPML evidence, translated archive paths, JetEngine Query Builder internal IDs plus custom Query IDs, identity collisions and Elementor/runtime topology evidence.

Reconciliation remains non-mutating and cannot auto-enable profiles or convert discovered routes into authority.

## Canonical URL boundary

Alpha13 intentionally does **not** activate `clean_filtered` canonical routing.

Clean public URLs remain deferred until runtime evidence proves route collisions, rewrite behavior, translated permalink behavior, canonical equivalence, redirects and JetSmartFilters compatibility.

Only the currently governed canonical modes remain available.

## Evidence discipline

Evidence IDs are references; they do not become trustworthy merely because a non-empty string is stored.

Before Production activation, external evidence should be retained with enough provenance to bind it to the exact candidate build and runtime, preferably including artifact identity and cryptographic hash where practical.

The Evidence Bundle remains read-only and cannot substitute for real runtime evidence.

## WP-CLI diagnostics

```bash
wp etg-dfsb inventory > runtime-inventory.json
wp etg-dfsb reconcile --previous=runtime-inventory.previous.json > reconciliation.json
```

## CI and runtime acceptance

Static/exact-head CI verifies code contracts, bounded behavior, vendor capability surfaces, browser transport regressions and deterministic release provenance.

CI green is not equivalent to Production readiness.

Before Ready for Review or Production activation, collect real runtime evidence for at least:

- exact certified package/build identity
- Runtime Inventory completeness
- exact custom Query IDs and Post Type bindings
- provider/query observation
- direct server-rendered Elementor HTML on filtered URLs
- live AJAX clear/reset/mixed-filter transitions
- Term Meta selector/value/AJAX path
- Rank Math output in HTML source
- WPML translated slugs and hreflang without fallback
- frontend/request/background result-count parity
- Global-OFF empty live ETG sitemap
- bounded Global-ON staging sitemap behavior
- AJAX rate protection (persistent object cache or external WAF/server layer)
- REST and sitemap TTFB/query-count/memory baselines

## Current authority boundary

Alpha13 does **not** authorize merge or Production activation by release alone.

It does not infer Production Query Builder/taxonomy authority from discovery, auto-approve taxonomy permutations, create synthetic WordPress Pages, treat sitemap discovery as indexing authority, or treat Elementor template/editor existence as automatic publication verification.

`merge_authorized=false`

`production_activation_authorized=false`

Exact runtime evidence and a separate explicit activation decision remain operator-controlled.
