# ETG Dynamic Filter SEO Bridge

Operational Alpha `0.4.0-alpha.8` is a governed Surface Profile Engine and SEO publication layer for JetSmartFilters + JetEngine filtered archives with WPML, Elementor Theme Builder and Rank Math.

It does not create fake WordPress Pages for filter combinations. It resolves exact filtered URL state at runtime, composes visible Term-driven archive content, applies guarded Rank Math metadata, and can publish only explicitly approved/indexable dynamic URLs through a Rank Math sitemap provider.

Vendor source is never edited.

## Safety model

The Global bridge defaults **OFF**.

```text
discover
→ inspect runtime inventory
→ configure exact profile authority
→ add/import Term content
→ build Elementor archive presentation
→ dark-render presentation if needed
→ verify presentation
→ approve exact language-bound combinations
→ preview SEO publication
→ explicit Global enable decision
```

It is never:

```text
discover → Cartesian-generate every taxonomy combination → auto-index
```

Global OFF means Rank Math metadata mutation, robots index authority and live ETG sitemap publication remain off. Read-only diagnostics and publication preview remain available.

### Elementor dark presentation

A profile may explicitly allow ETG shortcodes to render the resolved Term content while Global remains OFF:

```json
"elementor_render_when_global_off": true
```

This is a presentation-only dark-validation mode. It does not authorize Rank Math metadata, `index`, or sitemap publication. It is used so a Theme Builder archive can be inspected on real filtered URLs before SEO activation.

## Surface Profiles

`profiles_json` is the bounded source of structural authority. A profile may define:

- `post_types[]`
- `require_post_type_binding`
- `post_type_authority`: `query_builder|main_query|either|both`
- exact `archive_paths[]`
- exact `routes[]` of `{provider, query_id}`
- `taxonomy_rules{}`
- `allowed_taxonomy_sets[]`
- exact `indexable_combinations[]`
- per-depth result thresholds
- content-readiness policy
- canonical mode
- `travel|generic` composition mode
- publication policy

Example publication block:

```json
{
  "publication": {
    "sitemap": true,
    "hreflang": true,
    "schema": true,
    "social": true,
    "include_images_in_sitemap": true,
    "require_elementor_content": true,
    "elementor_render_when_global_off": false,
    "elementor_content_verified": false,
    "max_preview_urls": 100
  }
}
```

`elementor_content_verified=false` is a hard indexing deny when `require_elementor_content=true`. The plugin never auto-verifies a visual Elementor template.

## Exact combination authority

A publishable dynamic page must come from an exact profile- and language-bound signature:

```text
tours|en|location_jet=cairo|tour-types_jet=day-tours
properties|it|property_city=roma|property_type=hotel
```

No wildcard or traffic-derived approval is used.

Legacy language-only signatures may remain accepted by older request-time compatibility logic where configured, but the publication layer requires the profile ID in the signature before generating sitemap URLs.

## Elementor Theme Builder content

The same resolved translated Term context used for SEO metadata can be rendered as server-side HTML in Elementor.

### Combined presentation shortcodes

```text
[etg_filter_h1]
[etg_filter_intro]
[etg_filter_sections]
[etg_filter_gallery]
[etg_filter_gallery mode="priority" limit="8" size="large"]
[etg_filter_keyword]
[etg_filter_breadcrumb_context]
```

### Individual Term fields

```text
[etg_filter_term role="location" field="name"]
[etg_filter_term role="location" field="description" autop="1"]
[etg_filter_term role="tour_type" field="short_description" autop="1"]
[etg_filter_term role="style" field="description" autop="1"]
```

A Term can also be addressed by taxonomy when required:

```text
[etg_filter_term taxonomy="location_jet" field="description" autop="1"]
```

Supported scalar fields include:

- `name`
- `slug`
- `description`
- `short_description`
- `seo_title`
- `meta_description`
- `focus_keyword`
- `image_url`
- `image_id`
- `count`
- `location_level`

### Semantic Term sections

```text
[etg_filter_term_section role="location" field="description" heading="1" heading_level="2"]
[etg_filter_term_section role="tour_type" field="description" heading="1" heading_level="2"]
```

These render crawlable server-side `<section>` HTML. Elementor controls layout and placement; ETG controls identity and content resolution.

## Term content source of truth

Standard taxonomy `description` is read from the translated `WP_Term`.

Term SEO/content/image fields can also be mapped through each taxonomy rule's `field_map`. Safe built-in fallbacks include Rank Math/SEO-style Term meta keys, short-description fields, image fields and gallery fields.

If existing Term content lives in JetEngine or ACF Term meta, map the existing keys rather than creating duplicate SEO-only copy. Content readiness, visible Elementor sections and metadata then share the same source.

## Content readiness

Indexing may require:

- a generated title
- a usable meta description
- a minimum deduplicated content corpus
- profile-specific thresholds
- explicit Elementor presentation verification

Repeated Term copy is deduplicated before character-count readiness. A filter can veto a ready result but cannot promote a failed base result into ready.

## Rank Math metadata publication

For a structurally resolved dynamic URL, ETG integrates with Rank Math for:

- frontend title
- meta description
- canonical
- robots `index|noindex` + `follow`
- OpenGraph type
- OpenGraph URL
- Facebook title
- Facebook description
- Facebook image
- Twitter title
- Twitter description
- Twitter image
- Twitter card type
- JSON-LD `CollectionPage`

Metadata mutation remains behind the structural identity guard. Wrong route, wrong Post Type, unknown filter, malformed state, missing Term or translation fallback cannot receive ETG SEO metadata.

## Dynamic Rank Math sitemap

Alpha8 registers an `etg-filter-seo` sitemap provider with Rank Math.

Expected first sitemap URL:

```text
/etg-filter-seo-sitemap.xml
```

When the eligible URL count exceeds Rank Math's configured per-sitemap maximum, the provider emits paginated files such as:

```text
/etg-filter-seo-sitemap1.xml
/etg-filter-seo-sitemap2.xml
```

The live provider returns no ETG URLs while Global is OFF.

A URL enters the live sitemap only when its final indexing decision is `index=true`. The candidate must therefore pass profile, route, taxonomy-set, exact combination, Term, translation, content, Elementor verification, authoritative result-count and minimum-result gates.

Up to five priority gallery images may accompany a sitemap URL when enabled.

## Sitemap freshness

Rank Math sitemap storage is invalidated on relevant changes including:

- post save/delete
- object/Term relation changes
- Term create/edit/delete
- Term meta add/update/delete
- ETG configuration changes

Eligibility is recomputed after invalidation. A URL that becomes empty, thin, unapproved or otherwise non-indexable must disappear from the refreshed sitemap.

## Background result-count authority

The normal request-time count adapter mirrors JetSmartFilters filtered request state.

Sitemap generation has no live JetSmartFilters request, so Alpha8 adds a separate publication count probe. It:

1. resolves the exact configured JetEngine Query Builder query;
2. requires a `posts` query and bounded `post_type`;
3. preserves the Query Builder base arguments;
4. applies the exact approved taxonomy filters;
5. runs a bounded `WP_Query` count;
6. fails closed when any identity or Term is unavailable.

It never substitutes an unfiltered archive count.

## WPML hreflang

On valid dynamic pages, ETG can replace WPML hreflang targets with dynamic filter URLs.

A target language is emitted only when:

- every selected Term resolves to a real translation without fallback;
- the translated Term slugs form their own exact profile-bound approved combination;
- the target dynamic URL independently passes publication/index policy.

Missing language authority is omitted rather than fabricated. `x-default` may point to the approved default-language dynamic URL.

## Schema

Eligible pages may receive a Rank Math JSON-LD `CollectionPage` node containing:

- canonical `@id`
- canonical URL
- composed name
- composed description
- language
- `about` entities derived from resolved Terms

The Schema node is not added when the final indexing decision is not `index=true`.

## Admin operations

### Settings → ETG Filter SEO

Tabs:

- Overview
- Configuration
- Discovery
- Runtime Inventory
- Reconciliation
- URL Inspector
- Scenario Lab

The Global state, readiness, configuration revision and profile count remain visible. Every configuration control/action includes an inline explanation.

### Settings → ETG SEO Publication

Tabs:

- Overview
- Candidates
- Elementor Content
- Sitemap & Discovery

Candidate Preview is read-only. It can evaluate real Terms, metadata, background counts, content readiness and exclusion reasons with Global OFF. It never writes profile authority or live sitemap entries.

## Runtime inventory and reconciliation

Runtime Inventory v2 remains read-only and inventories:

- public Post Types
- taxonomy relations
- WPML language evidence
- translated archive paths
- JetEngine Query Builder structural identities
- query identity collisions
- completeness/truncation evidence

Eligible inventory must declare:

```text
contract=etg.dfsb.runtime-inventory.v2
evidence_complete=true
availability_errors=[]
```

Unavailable sources return `etg.dfsb.runtime-inventory-unavailable.v1` and cannot grant authority.

Reconciliation remains non-mutating and cannot auto-enable profiles or copy discovered routes into authority.

## Post Type authority

For `require_post_type_binding=true`, the safe default is Query Builder authority. The exact JetEngine query must be a Posts query with bounded Post Types. `post_type=any`, missing queries, non-post queries and Post Types outside the profile fail closed.

## Operational bounds

Current bounds remain deliberate:

- up to 50 profiles
- up to 20 exact routes per profile
- up to 50 taxonomy rules per profile
- up to 5,000 exact combinations per profile
- up to 5,000 evaluated live publication URLs per request

Invalid/overflowed authority fails visibly rather than silently expanding scope.

## Verified bundled compatibility surfaces

The CI verifies the repository-bundled versions/capabilities for:

- JetSmartFilters 3.8.3.1
- JetEngine 3.8.11.2
- Rank Math SEO 1.0.275
- Rank Math SEO PRO 3.0.118
- WPML Multilingual CMS 4.9.6
- WPML SEO 2.2.5
- WPML String Translation 3.5.3

Alpha8 additionally verifies Rank Math sitemap Provider/Router/Cache capability surfaces and WPML language/permalink publication sources.

## WP-CLI diagnostics

```bash
wp etg-dfsb inventory > runtime-inventory.json
wp etg-dfsb reconcile --previous=runtime-inventory.previous.json > reconciliation.json
```

## Operational boundary

Alpha8 does **not** authorize Production activation by release alone.

It also does not:

- infer the correct Production Query Builder route when runtime evidence does not prove it;
- auto-approve every possible taxonomy permutation;
- create synthetic WordPress Pages for filter URLs;
- treat sitemap discovery as indexing authority;
- treat Elementor template existence as automatic content verification.

Exact runtime route evidence, approved combinations, visible Elementor content and explicit activation remain operator-controlled.
